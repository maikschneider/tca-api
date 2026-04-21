<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessorInterface;
use MaikSchneider\TcaApi\Serializer\FileProcessing\ImageProcessor;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use MaikSchneider\TcaApi\Utility\UidListParser;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\Field\FileFieldType;
use TYPO3\CMS\Core\Schema\Field\GroupFieldType;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Serializes a TCA domain record to a Hydra JSON-LD array.
 *
 * All data is read directly from raw DB rows — no RecordFactory, no RecordInterface.
 * The Schema API (TcaSchemaFactory) is used to introspect field types and relationship
 * cardinality. File references are resolved via FileRepository::findByRelation().
 *
 * Embed config (per column):
 *   'embed' => true              — embed full related record at depth 1
 *   'embed' => ['depth' => N]   — embed N levels deep
 *   (no 'embed' key)            — return shallow stub {@id, @type, uid}  [default]
 */
final class ResourceSerializer
{
    private const DEFAULT_API_PREFIX = '/_api';

    /** @var array<string, TcaSchema> Schemas cached per table to avoid repeated factory calls during collection serialization. */
    private array $schemaCache = [];

    /** @var array<string, array<string, ColumnDefinition>> Column maps cached per table+mode to avoid rebuilding on every row in a collection. */
    private array $columnMapCache = [];

    public function __construct(
        private readonly TcaSchemaFactory $schemaFactory,
        private readonly DataRepository $dataRepository,
        private readonly FileRepository $fileRepository,
    ) {
    }

    /**
     * Serialize a single raw DB row.
     *
     * @param array  $preloaded      ['rows' => [foreignTable => [uid => row]], 'relations' => [column => [parentUid => [uid, ...]]]]
     * @param int    $remainingDepth -1 = top level (use per-column embed config); ≥0 = recursive budget from parent embed
     * @param array  $visited        ['table:uid' => true] cycle-prevention guard
     * @param string $operation      Current operation context: 'list', 'show', 'create', 'update', or '' for default
     */
    public function serialize(
        array $row,
        ApiDefinition $config,
        string $baseUrl,
        array $fields = [],
        array $preloaded = [],
        int $remainingDepth = -1,
        array $visited = [],
        string $operation = '',
    ): array {
        $uid            = (int)$row['uid'];
        $schema         = $this->getSchema($config->table);
        $columnMap      = $this->resolveColumnMap($config);

        // Derive the API prefix from $baseUrl by stripping the resource name portion.
        // e.g. '/_api/articles' → '/_api', '/custom-api/articles' → '/custom-api'
        $apiPrefix = \strlen($config->resourceName) > 0
            ? rtrim(substr($baseUrl, 0, \strlen($baseUrl) - \strlen($config->resourceName)), '/')
            : rtrim($baseUrl, '/');

        $result = [
            '@type' => $config->resourceType,
            '@id'   => $baseUrl . '/' . $uid,
            'uid'   => $uid,
        ];

        foreach ($columnMap as $column => $columnDef) {
            // Visibility gate — default mode: all columns pass through
            if ($config->isExplicitMode && !$columnDef->isReadable($operation)) {
                continue;
            }

            if ($fields !== [] && !\in_array($column, $fields, true)) {
                continue;
            }
            if (!$schema->hasField($column)) {
                continue;
            }

            $field = $schema->getField($column);

            if ($field instanceof FileFieldType) {
                $result[$column] = $this->serializeFileField($column, $field, $columnDef, $config->table, $uid);
                continue;
            }

            if (!($field instanceof RelationalFieldTypeInterface)) {
                $result[$column] = $this->applyColumnProcessor($row[$column] ?? null, $columnDef, $result, $row);
                continue;
            }

            if ($field->getRelationshipType()->hasOne()) {
                $propertyName          = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;
                $result[$propertyName] = $this->serializeHasOne($column, $columnDef, $config, $row, $field, $preloaded, $remainingDepth, $visited, $operation, $apiPrefix);
                continue;
            }

            $result[$column] = $this->serializeHasManyField($column, $columnDef, $config, $row, $field, $preloaded, $remainingDepth, $visited, $operation, $apiPrefix);
        }

        foreach ($config->virtualProperties as $virtualPropertyName => $virtualPropDef) {
            // Visibility gate — same logic as column groups
            if ($config->isExplicitMode && !$virtualPropDef->isReadable($operation)) {
                continue;
            }

            $columnRef   = $virtualPropDef->column;
            $columnField = null;
            if ($columnRef !== null && $schema->hasField($columnRef)) {
                $columnField = $schema->getField($columnRef);
            }

            if ($columnField instanceof FileFieldType) {
                // File column reference: fetch file refs for the source column, process with VP's own config
                $result[$virtualPropertyName] = $this->serializeFileField($columnRef, $columnField, $virtualPropDef, $config->table, $uid);
            } elseif ($virtualPropDef->processor !== null) {
                $value = $columnRef !== null ? ($row[$columnRef] ?? null) : null;
                $result[$virtualPropertyName] = $this->applyColumnProcessor($value, $virtualPropDef, $result, $row);
            } else {
                /** @var array{class-string, string} $callback */
                $callback = $virtualPropDef->callback;
                [$class, $method] = $callback;
                $result[$virtualPropertyName] = GeneralUtility::makeInstance($class)->$method($result, $row);
            }
        }

        return $result;
    }

    public function serializeCollection(
        array $rows,
        ApiDefinition $config,
        string $baseUrl,
        array $fields = [],
        array $preloaded = [],
        string $operation = '',
    ): array {
        return array_map(
            fn (array $row) => $this->serialize($row, $config, $baseUrl, $fields, $preloaded, -1, [], $operation),
            $rows,
        );
    }

    // ── File fields ───────────────────────────────────────────────────────────

    /**
     * type=file always has foreign_field set (by TcaPreparation), making RelationshipType=OneToMany
     * and hasOne() always false. For single-file fields we check maxitems directly.
     */
    private function serializeFileField(string $column, FileFieldType $field, ColumnDefinition $columnDef, string $table, int $uid): mixed
    {
        $processor = $this->resolveFileProcessor($columnDef);
        $fileRefs  = $this->fileRepository->findByRelation($table, $column, $uid);

        if (($field->getConfiguration()['maxitems'] ?? 0) === 1) {
            return isset($fileRefs[0]) ? $processor->process($fileRefs[0], $columnDef) : null;
        }

        return array_map(fn ($ref) => $processor->process($ref, $columnDef), $fileRefs);
    }

    // ── HasOne ────────────────────────────────────────────────────────────────

    /**
     * Serialize a hasOne relational field, with optional deep embedding.
     *
     * $remainingDepth == -1  → top level: resolve embed depth from $columnDef
     * $remainingDepth >= 0   → recursive call: use remaining budget
     */
    private function serializeHasOne(
        string $column,
        ColumnDefinition $columnDef,
        ApiDefinition $config,
        array $row,
        FieldTypeInterface&RelationalFieldTypeInterface $fieldObj,
        array $preloaded,
        int $remainingDepth,
        array $visited,
        string $operation = '',
        string $apiPrefix = self::DEFAULT_API_PREFIX,
    ): mixed {
        $fkValue      = (int)($row[$column] ?? 0);
        $foreignTable = $fieldObj->getConfiguration()['foreign_table'] ?? null;

        if ($fkValue <= 0 || $foreignTable === null) {
            return null;
        }

        $effectiveDepth = $remainingDepth >= 0 ? $remainingDepth : $columnDef->embedDepth();

        // For self-referential relations use the current config directly so that embed column
        // definitions (e.g. parent_id with embed:true on an article resource) are preserved
        // through recursive calls instead of falling back to a different ApiRegistry entry.
        $relatedConfig = $this->resolveRelatedConfig($foreignTable, $config, $columnDef);

        if ($relatedConfig === null && $effectiveDepth > 0 && !isset($visited[$foreignTable . ':' . $fkValue])) {
            $relatedConfig = $this->buildDefaultConfig($foreignTable, $columnDef);
        }

        $resourceName = $columnDef->resourceName ?? ($relatedConfig->resourceName ?? $foreignTable);
        $resourceType = $columnDef->resourceType ?? ($relatedConfig->resourceType ?? $foreignTable);

        if ($effectiveDepth <= 0 || isset($visited[$foreignTable . ':' . $fkValue]) || $relatedConfig === null) {
            return $this->buildStub($resourceName, $resourceType, $fkValue, $apiPrefix);
        }

        $relatedRow = $preloaded['rows'][$foreignTable][$fkValue]
            ?? $this->dataRepository->findById($foreignTable, $fkValue, $relatedConfig);

        if ($relatedRow === null) {
            return null;
        }

        return $this->serialize(
            $relatedRow,
            $relatedConfig,
            $apiPrefix . '/' . $relatedConfig->resourceName,
            [],
            $preloaded,
            $effectiveDepth - 1,
            $visited + [$config->table . ':' . (int)$row['uid'] => true],
            $operation,
        );
    }

    // ── HasMany ───────────────────────────────────────────────────────────────

    /** Dispatch hasMany serialization: group field or standard relational field. */
    private function serializeHasManyField(
        string $column,
        ColumnDefinition $columnDef,
        ApiDefinition $config,
        array $row,
        FieldTypeInterface&RelationalFieldTypeInterface $field,
        array $preloaded,
        int $remainingDepth,
        array $visited,
        string $operation = '',
        string $apiPrefix = self::DEFAULT_API_PREFIX,
    ): array {
        $effectiveDepth = $remainingDepth >= 0 ? $remainingDepth : $columnDef->embedDepth();

        if ($field instanceof GroupFieldType) {
            return $this->serializeGroupField($column, $field->getConfiguration(), $columnDef, $config, $row, $preloaded, $effectiveDepth, $visited, $operation, $apiPrefix);
        }

        $foreignTable = $field->getConfiguration()['foreign_table'] ?? null;
        if ($foreignTable === null) {
            return [];
        }

        $relatedRows = $this->resolveHasManyRows($column, $foreignTable, (int)$row['uid'], $row, $field, $preloaded);

        return $relatedRows !== []
            ? $this->serializeHasManyFromRows($foreignTable, $columnDef, $config, $row, $relatedRows, $preloaded, $effectiveDepth, $visited, $operation, $apiPrefix)
            : [];
    }

    /**
     * Fast path: serialize a hasMany relation from raw rows (preloaded or freshly fetched).
     * Handles depth=0 (shallow stubs) and depth>0 (recursive full embed) with cycle detection.
     */
    private function serializeHasManyFromRows(
        string $foreignTable,
        ColumnDefinition $columnDef,
        ApiDefinition $config,
        array $row,
        array $relatedRows,
        array $preloaded,
        int $effectiveDepth,
        array $visited,
        string $operation = '',
        string $apiPrefix = self::DEFAULT_API_PREFIX,
    ): array {
        $relatedConfig = $this->resolveRelatedConfig($foreignTable, $config, $columnDef);

        if ($relatedConfig === null && $effectiveDepth > 0) {
            $relatedConfig = $this->buildDefaultConfig($foreignTable, $columnDef);
        }

        $resourceName = $columnDef->resourceName ?? ($relatedConfig->resourceName ?? $foreignTable);
        $resourceType = $columnDef->resourceType ?? ($relatedConfig->resourceType ?? $foreignTable);

        if ($effectiveDepth <= 0 || $relatedConfig === null) {
            return array_map(fn (array $r) => $this->buildStub($resourceName, $resourceType, (int)$r['uid'], $apiPrefix), $relatedRows);
        }

        $relatedBaseUrl = $apiPrefix . '/' . $relatedConfig->resourceName;
        $newVisited     = $visited + [$config->table . ':' . (int)$row['uid'] => true];

        $result = [];
        foreach ($relatedRows as $relatedRow) {
            $itemUid = (int)$relatedRow['uid'];

            if (isset($newVisited[$foreignTable . ':' . $itemUid])) {
                $result[] = $this->buildStub($resourceName, $resourceType, $itemUid, $apiPrefix);
                continue;
            }

            $result[] = $this->serialize($relatedRow, $relatedConfig, $relatedBaseUrl, [], $preloaded, $effectiveDepth - 1, $newVisited, $operation);
        }

        return $result;
    }

    /** Resolve hasMany rows from the preloaded pool, falling back to a direct DB fetch. */
    private function resolveHasManyRows(
        string $column,
        string $foreignTable,
        int $parentUid,
        array $row,
        FieldTypeInterface&RelationalFieldTypeInterface $field,
        array $preloaded,
    ): array {
        if (isset($preloaded['relations'][$column])) {
            return UidListParser::mapToRows(
                $preloaded['relations'][$column][$parentUid] ?? [],
                $preloaded['rows'][$foreignTable] ?? [],
            );
        }

        return $this->fetchHasManyRows($column, $field, $row);
    }

    /**
     * Fetch hasMany rows directly from DB for a single parent (slow path — not preloaded).
     */
    private function fetchHasManyRows(string $column, FieldTypeInterface&RelationalFieldTypeInterface $fieldObj, array $row): array
    {
        $fieldConfig  = $fieldObj->getConfiguration();
        $foreignTable = $fieldConfig['foreign_table'] ?? null;
        if ($foreignTable === null) {
            return [];
        }

        $parentUid = (int)$row['uid'];
        $mmTable   = $fieldConfig['MM'] ?? null;

        if ($mmTable !== null) {
            $hasOppositeField = isset($fieldConfig['MM_opposite_field']);
            $grouped = $this->dataRepository->findHasManyByMM(
                $foreignTable,
                [$parentUid],
                $mmTable,
                $hasOppositeField ? 'uid_foreign' : 'uid_local',
                $hasOppositeField ? 'uid_local'  : 'uid_foreign',
                $fieldConfig['MM_match_fields'] ?? [],
            );
            return $grouped[$parentUid] ?? [];
        }

        if (isset($fieldConfig['foreign_field'])) {
            $grouped = $this->dataRepository->findHasManyByForeignField($foreignTable, $fieldConfig['foreign_field'], [$parentUid]);
            return $grouped[$parentUid] ?? [];
        }

        // UID list stored in parent row's own column (no MM, no foreign_field).
        $uids = GeneralUtility::intExplode(',', (string)($row[$column] ?? ''), true);
        return $uids !== []
            ? UidListParser::mapToRows($uids, $this->dataRepository->findByIds($foreignTable, $uids))
            : [];
    }

    // ── Group fields ──────────────────────────────────────────────────────────

    /**
     * Serialize a type=group hasMany field.
     * Dispatches to single-table (UID list or MM) or multi-table (prefix-format stubs) path.
     */
    private function serializeGroupField(
        string $column,
        array $fieldConfig,
        ColumnDefinition $columnDef,
        ApiDefinition $config,
        array $row,
        array $preloaded,
        int $effectiveDepth,
        array $visited,
        string $operation = '',
        string $apiPrefix = self::DEFAULT_API_PREFIX,
    ): array {
        $allowedTables = GeneralUtility::trimExplode(',', $fieldConfig['allowed'] ?? '', true);

        if ($allowedTables === []) {
            return [];
        }

        if (count($allowedTables) === 1) {
            return $this->serializeSingleTableGroup($column, $fieldConfig, $columnDef, $config, $row, $preloaded, $effectiveDepth, $visited, $allowedTables[0], $operation, $apiPrefix);
        }

        return $this->serializeMultiTableGroup($column, $columnDef, $config, $row, $apiPrefix);
    }

    /** Single allowed table: preloaded pool → MM slow path → UID-list slow path. */
    private function serializeSingleTableGroup(
        string $column,
        array $fieldConfig,
        ColumnDefinition $columnDef,
        ApiDefinition $config,
        array $row,
        array $preloaded,
        int $effectiveDepth,
        array $visited,
        string $foreignTable,
        string $operation = '',
        string $apiPrefix = self::DEFAULT_API_PREFIX,
    ): array {
        $uid     = (int)$row['uid'];
        $mmTable = $fieldConfig['MM'] ?? null;

        if (isset($preloaded['relations'][$column])) {
            $relatedRows = UidListParser::mapToRows(
                $preloaded['relations'][$column][$uid] ?? [],
                $preloaded['rows'][$foreignTable] ?? [],
            );
        } elseif ($mmTable !== null) {
            $hasOppositeField = isset($fieldConfig['MM_opposite_field']);
            $grouped     = $this->dataRepository->findHasManyByMM(
                $foreignTable,
                [$uid],
                $mmTable,
                $hasOppositeField ? 'uid_foreign' : 'uid_local',
                $hasOppositeField ? 'uid_local'  : 'uid_foreign',
                $fieldConfig['MM_match_fields'] ?? [],
            );
            $relatedRows = $grouped[$uid] ?? [];
        } else {
            $uids        = GeneralUtility::intExplode(',', (string)($row[$column] ?? ''), true);
            $relatedRows = $uids !== []
                ? UidListParser::mapToRows($uids, $this->dataRepository->findByIds($foreignTable, $uids))
                : [];
        }

        return $relatedRows !== []
            ? $this->serializeHasManyFromRows($foreignTable, $columnDef, $config, $row, $relatedRows, $preloaded, $effectiveDepth, $visited, $operation, $apiPrefix)
            : [];
    }

    /** Multiple allowed tables: parse "tablename_uid" prefix format and return stubs. */
    private function serializeMultiTableGroup(string $column, ColumnDefinition $columnDef, ApiDefinition $config, array $row, string $apiPrefix = self::DEFAULT_API_PREFIX): array
    {
        $items = $this->parseMultiTableGroupValues(trim((string)($row[$column] ?? '')));

        return array_map(function (array $item) use ($columnDef, $config, $apiPrefix): array {
            $relatedConfig = $this->resolveRelatedConfig($item['table'], $config);
            $resourceName  = $columnDef->resourceName ?? ($relatedConfig->resourceName ?? $item['table']);
            $resourceType  = $columnDef->resourceType ?? ($relatedConfig->resourceType ?? $item['table']);
            return $this->buildStub($resourceName, $resourceType, $item['uid'], $apiPrefix);
        }, $items);
    }

    /**
     * Parse a multi-table group value string into [{table, uid}] items, preserving order.
     * Format: "tablename_uid,tablename_uid" e.g. "pages_1,sys_file_3"
     */
    private function parseMultiTableGroupValues(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $result = [];
        foreach (GeneralUtility::trimExplode(',', $raw, true) as $item) {
            $pos = strrpos($item, '_');
            if ($pos === false) {
                continue;
            }
            $table = substr($item, 0, $pos);
            $uid   = (int)substr($item, $pos + 1);
            if ($uid > 0 && $table !== '') {
                $result[] = ['table' => $table, 'uid' => $uid];
            }
        }

        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildStub(string $resourceName, string $resourceType, int $uid, string $apiPrefix = self::DEFAULT_API_PREFIX): array
    {
        return ['@id' => $apiPrefix . '/' . $resourceName . '/' . $uid, '@type' => $resourceType, 'uid' => $uid];
    }

    /**
     * Synthesize a minimal default-mode config for a table with no API registration.
     * 'columns' => [] with no 'groups' key → isExplicitMode = false → all TCA columns exposed.
     */
    private function buildDefaultConfig(string $foreignTable, ?ColumnDefinition $columnDef = null): ApiDefinition
    {
        return ApiDefinition::fromArray([
            'general' => [
                'table'        => $foreignTable,
                'resourceName' => $columnDef->resourceName ?? $foreignTable,
                'resourceType' => $columnDef->resourceType ?? $foreignTable,
                'operations'   => [],
            ],
            'columns' => [],
        ]);
    }

    /**
     * Build the column map for iteration in serialize().
     *
     * Explicit mode: returns $config->columns as-is.
     * Default mode: returns all exposable TCA columns, using $config->columns overrides where set.
     *
     * Result is cached per table+mode to avoid rebuilding on every row during collection serialization.
     *
     * @return array<string, ColumnDefinition>
     */
    private function resolveColumnMap(ApiDefinition $config): array
    {
        $cacheKey = $config->table . $config->resourceName . ($config->isExplicitMode ? ':explicit' : ':default');

        if (isset($this->columnMapCache[$cacheKey])) {
            return $this->columnMapCache[$cacheKey];
        }

        if ($config->isExplicitMode) {
            return $this->columnMapCache[$cacheKey] = $config->columns;
        }

        $columnMap = [];
        foreach (TcaColumnDiscovery::getExposableColumnNames($config->table) as $colName) {
            $columnMap[$colName] = $config->columns[$colName] ?? new ColumnDefinition(groups: null);
        }

        return $this->columnMapCache[$cacheKey] = $columnMap;
    }

    private function applyColumnProcessor(mixed $value, ColumnDefinition $columnDef, array $serializedRow, array $rawRow): mixed
    {
        if ($columnDef->processor === null) {
            return $value;
        }

        /** @var class-string<ColumnProcessorInterface> $processorClass */
        $processorClass = $columnDef->processor;
        $processor = GeneralUtility::makeInstance($processorClass);

        return $processor->process($value, $columnDef, ['serializedRow' => $serializedRow, 'rawRow' => $rawRow]);
    }

    private function resolveFileProcessor(ColumnDefinition $columnDef): FileProcessorInterface
    {
        if ($columnDef->processor !== null) {
            /** @var class-string<FileProcessorInterface> $processorClass */
            $processorClass = $columnDef->processor;
            return GeneralUtility::makeInstance($processorClass);
        }
        return GeneralUtility::makeInstance(ImageProcessor::class);
    }

    /**
     * Resolve the API config for a related table.
     * For self-referential relations returns the current config to preserve embed definitions.
     * When $columnDef->resourceName is set, selects that specific ApiRegistry entry by name.
     */
    private function resolveRelatedConfig(string $foreignTable, ApiDefinition $config, ?ColumnDefinition $columnDef = null): ?ApiDefinition
    {
        if ($foreignTable === $config->table) {
            return $config;
        }

        if ($columnDef?->resourceName !== null) {
            return ApiRegistry::get($columnDef->resourceName);
        }

        return ApiRegistry::getByTable($foreignTable);
    }

    /** Returns the TcaSchema for a table, cached to avoid repeated factory calls per collection row. */
    private function getSchema(string $table): TcaSchema
    {
        return $this->schemaCache[$table] ??= $this->schemaFactory->get($table);
    }
}
