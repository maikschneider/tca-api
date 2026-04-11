<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessorInterface;
use MaikSchneider\TcaApi\Serializer\FileProcessing\ImageProcessor;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use MaikSchneider\TcaApi\Utility\UidListParser;
use TYPO3\CMS\Core\Resource\FileRepository;
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
class ResourceSerializer
{
    /** @var array<string, TcaSchema> Schemas cached per table to avoid repeated factory calls during collection serialization. */
    private array $schemaCache = [];

    public function __construct(
        private readonly TcaSchemaFactory $schemaFactory,
        private readonly DataRepository $dataRepository,
        private readonly FileRepository $fileRepository,
    ) {
    }

    /**
     * Serialize a single raw DB row.
     *
     * @param array $preloaded      ['rows' => [foreignTable => [uid => row]], 'relations' => [column => [parentUid => [uid, ...]]]]
     * @param int   $remainingDepth -1 = top level (use per-column embed config); ≥0 = recursive budget from parent embed
     * @param array $visited        ['table:uid' => true] cycle-prevention guard
     */
    public function serialize(
        array $row,
        array $config,
        string $baseUrl,
        array $fields = [],
        array $preloaded = [],
        int $remainingDepth = -1,
        array $visited = [],
    ): array {
        $table  = $config['general']['table'];
        $uid    = (int)$row['uid'];
        $schema = $this->getSchema($table);

        $result = [
            '@type' => $config['general']['resourceType'],
            '@id'   => $baseUrl . '/' . $uid,
            'uid'   => $uid,
        ];

        foreach ($config['columns'] as $column => $columnConfig) {
            if (!($columnConfig['readable'] ?? false)) {
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
                $result[$column] = $this->serializeFileField($column, $field, $columnConfig, $table, $uid);
                continue;
            }

            if (!($field instanceof RelationalFieldTypeInterface)) {
                $result[$column] = $this->applyColumnProcessor($row[$column] ?? null, $columnConfig, $result, $row);
                continue;
            }

            if ($field->getRelationshipType()->hasOne()) {
                $propertyName          = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;
                $result[$propertyName] = $this->serializeHasOne($column, $columnConfig, $config, $row, $field, $preloaded, $remainingDepth, $visited);
                continue;
            }

            $result[$column] = $this->serializeHasManyField($column, $columnConfig, $config, $row, $field, $preloaded, $remainingDepth, $visited);
        }

        foreach ($config['virtualProperties'] ?? [] as $virtualPropertyName => $virtualPropertyConfig) {
            if (isset($virtualPropertyConfig['processor'])) {
                $result[$virtualPropertyName] = $this->applyColumnProcessor(null, $virtualPropertyConfig, $result, $row);
            } else {
                [$class, $method] = $virtualPropertyConfig['callback'];
                $result[$virtualPropertyName] = GeneralUtility::makeInstance($class)->$method($result, $row);
            }
        }

        return $result;
    }

    public function serializeCollection(
        array $rows,
        array $config,
        string $baseUrl,
        array $fields = [],
        array $preloaded = [],
    ): array {
        return array_map(
            fn (array $row) => $this->serialize($row, $config, $baseUrl, $fields, $preloaded),
            $rows,
        );
    }

    // ── File fields ───────────────────────────────────────────────────────────

    /**
     * type=file always has foreign_field set (by TcaPreparation), making RelationshipType=OneToMany
     * and hasOne() always false. For single-file fields we check maxitems directly.
     */
    private function serializeFileField(string $column, FileFieldType $field, array $columnConfig, string $table, int $uid): mixed
    {
        $processor = $this->resolveFileProcessor($columnConfig);
        $fileRefs  = $this->fileRepository->findByRelation($table, $column, $uid);

        if (($field->getConfiguration()['maxitems'] ?? 0) === 1) {
            return isset($fileRefs[0]) ? $processor->process($fileRefs[0], $columnConfig) : null;
        }

        return array_map(fn ($ref) => $processor->process($ref, $columnConfig), $fileRefs);
    }

    // ── HasOne ────────────────────────────────────────────────────────────────

    /**
     * Serialize a hasOne relational field, with optional deep embedding.
     *
     * $remainingDepth == -1  → top level: resolve embed depth from $columnConfig
     * $remainingDepth >= 0   → recursive call: use remaining budget
     */
    private function serializeHasOne(
        string $column,
        array $columnConfig,
        array $config,
        array $row,
        RelationalFieldTypeInterface $fieldObj,
        array $preloaded,
        int $remainingDepth,
        array $visited,
    ): mixed {
        $fkValue      = (int)($row[$column] ?? 0);
        $foreignTable = $fieldObj->getConfiguration()['foreign_table'] ?? null;

        if ($fkValue <= 0 || $foreignTable === null) {
            return null;
        }

        $effectiveDepth = $remainingDepth >= 0 ? $remainingDepth : $this->resolveEmbedDepth($columnConfig);

        // For self-referential relations use the current config directly so that embed column
        // definitions (e.g. parent_id with embed:true on an article resource) are preserved
        // through recursive calls instead of falling back to a different ApiRegistry entry.
        $relatedConfig = $this->resolveRelatedConfig($foreignTable, $config);
        $resourceName  = $columnConfig['resourceName'] ?? ($relatedConfig !== null ? $relatedConfig['general']['resourceName'] : $foreignTable);
        $resourceType  = $columnConfig['resourceType'] ?? ($relatedConfig !== null ? $relatedConfig['general']['resourceType'] : $foreignTable);

        if ($effectiveDepth <= 0 || isset($visited[$foreignTable . ':' . $fkValue]) || $relatedConfig === null) {
            return $this->buildStub($resourceName, $resourceType, $fkValue);
        }

        $relatedRow = $preloaded['rows'][$foreignTable][$fkValue]
            ?? $this->dataRepository->findById($foreignTable, $fkValue, []);

        if ($relatedRow === null) {
            return null;
        }

        return $this->serialize(
            $relatedRow,
            $relatedConfig,
            '/_api/' . $relatedConfig['general']['resourceName'],
            [],
            $preloaded,
            $effectiveDepth - 1,
            $visited + [$config['general']['table'] . ':' . (int)$row['uid'] => true],
        );
    }

    // ── HasMany ───────────────────────────────────────────────────────────────

    /** Dispatch hasMany serialization: group field or standard relational field. */
    private function serializeHasManyField(
        string $column,
        array $columnConfig,
        array $config,
        array $row,
        RelationalFieldTypeInterface $field,
        array $preloaded,
        int $remainingDepth,
        array $visited,
    ): array {
        $effectiveDepth = $remainingDepth >= 0 ? $remainingDepth : $this->resolveEmbedDepth($columnConfig);

        if ($field instanceof GroupFieldType) {
            return $this->serializeGroupField($column, $field->getConfiguration(), $columnConfig, $config, $row, $preloaded, $effectiveDepth, $visited);
        }

        $foreignTable = $field->getConfiguration()['foreign_table'] ?? null;
        if ($foreignTable === null) {
            return [];
        }

        $relatedRows = $this->resolveHasManyRows($column, $foreignTable, (int)$row['uid'], $row, $field, $preloaded);

        return $relatedRows !== []
            ? $this->serializeHasManyFromRows($foreignTable, $columnConfig, $config, $row, $relatedRows, $preloaded, $effectiveDepth, $visited)
            : [];
    }

    /**
     * Fast path: serialize a hasMany relation from raw rows (preloaded or freshly fetched).
     * Handles depth=0 (shallow stubs) and depth>0 (recursive full embed) with cycle detection.
     */
    private function serializeHasManyFromRows(
        string $foreignTable,
        array $columnConfig,
        array $config,
        array $row,
        array $relatedRows,
        array $preloaded,
        int $effectiveDepth,
        array $visited,
    ): array {
        $relatedConfig = $this->resolveRelatedConfig($foreignTable, $config);
        $resourceName  = $columnConfig['resourceName'] ?? ($relatedConfig !== null ? $relatedConfig['general']['resourceName'] : $foreignTable);
        $resourceType  = $columnConfig['resourceType'] ?? ($relatedConfig !== null ? $relatedConfig['general']['resourceType'] : $foreignTable);

        if ($effectiveDepth <= 0 || $relatedConfig === null) {
            return array_map(fn (array $r) => $this->buildStub($resourceName, $resourceType, (int)$r['uid']), $relatedRows);
        }

        $relatedBaseUrl = '/_api/' . $relatedConfig['general']['resourceName'];
        $newVisited     = $visited + [$config['general']['table'] . ':' . (int)$row['uid'] => true];

        $result = [];
        foreach ($relatedRows as $relatedRow) {
            $itemUid = (int)$relatedRow['uid'];

            if (isset($newVisited[$foreignTable . ':' . $itemUid])) {
                $result[] = $this->buildStub($resourceName, $resourceType, $itemUid);
                continue;
            }

            $result[] = $this->serialize($relatedRow, $relatedConfig, $relatedBaseUrl, [], $preloaded, $effectiveDepth - 1, $newVisited);
        }

        return $result;
    }

    /** Resolve hasMany rows from the preloaded pool, falling back to a direct DB fetch. */
    private function resolveHasManyRows(
        string $column,
        string $foreignTable,
        int $parentUid,
        array $row,
        RelationalFieldTypeInterface $field,
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
    private function fetchHasManyRows(string $column, RelationalFieldTypeInterface $fieldObj, array $row): array
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
        $uids = UidListParser::parse((string)($row[$column] ?? ''));
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
        array $columnConfig,
        array $config,
        array $row,
        array $preloaded,
        int $effectiveDepth,
        array $visited,
    ): array {
        $allowedTables = GeneralUtility::trimExplode(',', $fieldConfig['allowed'] ?? '', true);

        if ($allowedTables === []) {
            return [];
        }

        if (count($allowedTables) === 1) {
            return $this->serializeSingleTableGroup($column, $fieldConfig, $columnConfig, $config, $row, $preloaded, $effectiveDepth, $visited, $allowedTables[0]);
        }

        return $this->serializeMultiTableGroup($column, $columnConfig, $config, $row);
    }

    /** Single allowed table: preloaded pool → MM slow path → UID-list slow path. */
    private function serializeSingleTableGroup(
        string $column,
        array $fieldConfig,
        array $columnConfig,
        array $config,
        array $row,
        array $preloaded,
        int $effectiveDepth,
        array $visited,
        string $foreignTable,
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
            $uids        = UidListParser::parse((string)($row[$column] ?? ''));
            $relatedRows = $uids !== []
                ? UidListParser::mapToRows($uids, $this->dataRepository->findByIds($foreignTable, $uids))
                : [];
        }

        return $relatedRows !== []
            ? $this->serializeHasManyFromRows($foreignTable, $columnConfig, $config, $row, $relatedRows, $preloaded, $effectiveDepth, $visited)
            : [];
    }

    /** Multiple allowed tables: parse "tablename_uid" prefix format and return stubs. */
    private function serializeMultiTableGroup(string $column, array $columnConfig, array $config, array $row): array
    {
        $items = $this->parseMultiTableGroupValues(trim((string)($row[$column] ?? '')));

        return array_map(function (array $item) use ($columnConfig, $config): array {
            $relatedConfig = $this->resolveRelatedConfig($item['table'], $config);
            $resourceName  = $columnConfig['resourceName'] ?? ($relatedConfig !== null ? $relatedConfig['general']['resourceName'] : $item['table']);
            $resourceType  = $columnConfig['resourceType'] ?? ($relatedConfig !== null ? $relatedConfig['general']['resourceType'] : $item['table']);
            return $this->buildStub($resourceName, $resourceType, $item['uid']);
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

    private function buildStub(string $resourceName, string $resourceType, int $uid): array
    {
        return ['@id' => '/_api/' . $resourceName . '/' . $uid, '@type' => $resourceType, 'uid' => $uid];
    }

    /** Returns 0 when no embed is configured. */
    private function resolveEmbedDepth(array $columnConfig): int
    {
        $embed = $columnConfig['embed'] ?? null;

        if ($embed === null || $embed === false) {
            return 0;
        }
        if ($embed === true) {
            return 1;
        }
        if (\is_array($embed)) {
            return max(0, (int)($embed['depth'] ?? $embed['maxDepth'] ?? 1));
        }

        return 0;
    }

    private function applyColumnProcessor(mixed $value, array $columnConfig, array $serializedRow, array $rawRow): mixed
    {
        $class = $columnConfig['processor'] ?? null;
        if ($class === null) {
            return $value;
        }

        /** @var ColumnProcessorInterface $processor */
        $processor = GeneralUtility::makeInstance($class);

        return $processor->process($value, $columnConfig, ['serializedRow' => $serializedRow, 'rawRow' => $rawRow]);
    }

    private function resolveFileProcessor(array $columnConfig): FileProcessorInterface
    {
        $class = $columnConfig['processor'] ?? null;

        return $class !== null
            ? GeneralUtility::makeInstance($class)
            : GeneralUtility::makeInstance(ImageProcessor::class);
    }

    /**
     * Resolve the API config for a related table.
     * For self-referential relations returns the current config to preserve embed definitions.
     */
    private function resolveRelatedConfig(string $foreignTable, array $config): ?array
    {
        return $foreignTable === $config['general']['table']
            ? $config
            : ApiRegistry::getByTable($foreignTable);
    }

    /** Returns the TcaSchema for a table, cached to avoid repeated factory calls per collection row. */
    private function getSchema(string $table): TcaSchema
    {
        return $this->schemaCache[$table] ??= $this->schemaFactory->get($table);
    }
}
