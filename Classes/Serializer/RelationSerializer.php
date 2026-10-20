<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tca\GroupAllowedResolver;
use MaikSchneider\TcaApi\Utility\UidListParser;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\Field\GroupFieldType;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Serializes hasOne and hasMany relational TCA fields.
 *
 * Handles foreign_field, MM, and UID-list relations with depth tracking
 * and cycle detection. Delegates recursive embedding back to the
 * ResourceSerializer orchestrator.
 */
final class RelationSerializer
{
    private const DEFAULT_API_PREFIX = '/_api';

    public function __construct(
        private readonly DataRepository $dataRepository,
        private readonly ApiRegistry $apiRegistry,
        private readonly GroupFieldSerializer $groupFieldSerializer,
    ) {
    }

    /**
     * The table a hasOne field points to: foreign_table, or the single allowed
     * table of a type=group field such as pages.shortcut.
     *
     * @param array<string, mixed> $fieldConfig
     */
    public static function hasOneForeignTable(array $fieldConfig): ?string
    {
        $foreignTable = $fieldConfig['foreign_table'] ?? null;
        if (\is_string($foreignTable) && $foreignTable !== '') {
            return $foreignTable;
        }
        if (($fieldConfig['type'] ?? null) !== 'group') {
            return null;
        }

        $resolver = new GroupAllowedResolver();
        $allowedTables = $resolver->resolveAllowedTables($fieldConfig);

        return \count($allowedTables) === 1 && !$resolver->isWildcard($fieldConfig) ? $allowedTables[0] : null;
    }

    /**
     * Serialize a hasOne relational field, with optional deep embedding.
     *
     * $remainingDepth == -1  → top level: resolve embed depth from $columnDef
     * $remainingDepth >= 0   → recursive call: use remaining budget
     */
    public function serializeHasOne(
        string $column,
        ColumnDefinition $columnDef,
        ApiDefinition $config,
        array $row,
        FieldTypeInterface&RelationalFieldTypeInterface $fieldObj,
        array $preloaded,
        int $remainingDepth,
        array $visited,
        string $operation,
        string $apiPrefix,
        ResourceSerializer $serializer,
    ): mixed {
        $fkValue      = (int)($row[$column] ?? 0);
        $foreignTable = self::hasOneForeignTable($fieldObj->getConfiguration());

        if ($fkValue <= 0 || $foreignTable === null) {
            return null;
        }

        $effectiveDepth = $remainingDepth >= 0 ? $remainingDepth : $columnDef->embedDepth();
        $relatedConfig  = $this->resolveRelatedConfig($foreignTable, $config, $columnDef);

        if ($relatedConfig === null && $effectiveDepth > 0 && !isset($visited[$foreignTable . ':' . $fkValue])) {
            $relatedConfig = self::buildDefaultConfig($foreignTable, $columnDef);
        }

        $resourceName = $columnDef->resourceName ?? ($relatedConfig->resourceName ?? $foreignTable);
        $resourceType = $columnDef->resourceType ?? ($relatedConfig->resourceType ?? $foreignTable);

        if ($effectiveDepth <= 0 || isset($visited[$foreignTable . ':' . $fkValue]) || $relatedConfig === null) {
            return self::reference($relatedConfig, $resourceName, $fkValue, $apiPrefix);
        }

        $relatedRow = $preloaded['rows'][$foreignTable][$fkValue]
            ?? $this->dataRepository->findById($foreignTable, $fkValue, $relatedConfig, $preloaded['__language'] ?? null);

        if ($relatedRow === null) {
            return null;
        }

        return $serializer->serialize(
            $relatedRow,
            $relatedConfig,
            $apiPrefix . '/' . $relatedConfig->resourceName,
            $columnDef->embedColumns(),
            $preloaded,
            $effectiveDepth - 1,
            $visited + [$config->table . ':' . (int)$row['uid'] => true],
            $operation,
        );
    }

    /** Dispatch hasMany serialization: group field or standard relational field. */
    public function serializeHasManyField(
        string $column,
        ColumnDefinition $columnDef,
        ApiDefinition $config,
        array $row,
        FieldTypeInterface&RelationalFieldTypeInterface $field,
        array $preloaded,
        int $remainingDepth,
        array $visited,
        string $operation,
        string $apiPrefix,
        ResourceSerializer $serializer,
    ): array {
        $effectiveDepth = $remainingDepth >= 0 ? $remainingDepth : $columnDef->embedDepth();

        if ($field instanceof GroupFieldType) {
            return $this->groupFieldSerializer->serialize($column, $field->getConfiguration(), $columnDef, $config, $row, $preloaded, $effectiveDepth, $visited, $operation, $apiPrefix, $this, $serializer);
        }

        $foreignTable = $field->getConfiguration()['foreign_table'] ?? null;
        if ($foreignTable === null) {
            return [];
        }

        $relatedRows = $this->resolveHasManyRows($column, $foreignTable, (int)$row['uid'], $row, $field, $preloaded, $config->table);

        return $relatedRows !== []
            ? $this->serializeHasManyFromRows($foreignTable, $columnDef, $config, $row, $relatedRows, $preloaded, $effectiveDepth, $visited, $operation, $apiPrefix, $serializer)
            : [];
    }

    /**
     * Serialize a hasMany relation from raw rows (preloaded or freshly fetched).
     * Handles depth=0 (shallow stubs) and depth>0 (recursive full embed) with cycle detection.
     */
    public function serializeHasManyFromRows(
        string $foreignTable,
        ColumnDefinition $columnDef,
        ApiDefinition $config,
        array $row,
        array $relatedRows,
        array $preloaded,
        int $effectiveDepth,
        array $visited,
        string $operation,
        string $apiPrefix,
        ResourceSerializer $serializer,
    ): array {
        $relatedConfig = $this->resolveRelatedConfig($foreignTable, $config, $columnDef);

        if ($relatedConfig === null && $effectiveDepth > 0) {
            $relatedConfig = self::buildDefaultConfig($foreignTable, $columnDef);
        }

        $resourceName = $columnDef->resourceName ?? ($relatedConfig->resourceName ?? $foreignTable);
        $resourceType = $columnDef->resourceType ?? ($relatedConfig->resourceType ?? $foreignTable);

        if ($effectiveDepth <= 0) {
            return array_map(fn (array $r) => self::reference($relatedConfig, $resourceName, (int)$r['uid'], $apiPrefix), $relatedRows);
        }

        $relatedBaseUrl = $apiPrefix . '/' . $relatedConfig->resourceName;
        $newVisited     = $visited + [$config->table . ':' . (int)$row['uid'] => true];

        $result = [];
        foreach ($relatedRows as $relatedRow) {
            $itemUid = (int)$relatedRow['uid'];

            if (isset($newVisited[$foreignTable . ':' . $itemUid])) {
                $result[] = self::reference($relatedConfig, $resourceName, $itemUid, $apiPrefix);
                continue;
            }

            $result[] = $serializer->serialize($relatedRow, $relatedConfig, $relatedBaseUrl, $columnDef->embedColumns(), $preloaded, $effectiveDepth - 1, $newVisited, $operation);
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
        string $parentTable,
    ): array {
        if (isset($preloaded['relations'][$parentTable][$column][$parentUid])) {
            return UidListParser::mapToRows(
                $preloaded['relations'][$parentTable][$column][$parentUid],
                $preloaded['rows'][$foreignTable] ?? [],
            );
        }

        return $this->fetchHasManyRows($column, $field, $row, $parentTable, $preloaded);
    }

    /**
     * Fetch hasMany rows directly from DB for a single parent (slow path — not preloaded).
     */
    private function fetchHasManyRows(string $column, FieldTypeInterface&RelationalFieldTypeInterface $fieldObj, array $row, string $parentTable, array $preloaded = []): array
    {
        $fieldConfig  = $fieldObj->getConfiguration();
        $foreignTable = $fieldConfig['foreign_table'] ?? null;
        if ($foreignTable === null) {
            return [];
        }

        $parentUid = (int)$row['uid'];
        $mmTable   = $fieldConfig['MM'] ?? null;
        $language  = $preloaded['__language'] ?? null;

        if ($mmTable !== null) {
            $hasOppositeField = isset($fieldConfig['MM_opposite_field']);
            $grouped = $this->dataRepository->findHasManyByMM(
                $foreignTable,
                [$parentUid],
                $mmTable,
                $hasOppositeField ? 'uid_foreign' : 'uid_local',
                $hasOppositeField ? 'uid_local'  : 'uid_foreign',
                $fieldConfig['MM_match_fields'] ?? [],
                $language,
            );
            return $grouped[$parentUid] ?? [];
        }

        if (isset($fieldConfig['foreign_field'])) {
            $grouped = $this->dataRepository->findHasManyByForeignField(
                $foreignTable,
                $fieldConfig['foreign_field'],
                [$parentUid],
                $fieldConfig['foreign_table_field'] ?? null,
                $parentTable,
                $fieldConfig['foreign_match_fields'] ?? [],
                $language,
            );
            return $grouped[$parentUid] ?? [];
        }

        // UID list stored in parent row's own column (no MM, no foreign_field).
        $uids = GeneralUtility::intExplode(',', (string)($row[$column] ?? ''), true);
        return $uids !== []
            ? UidListParser::mapToRows($uids, $this->dataRepository->findByIdsWithOverlay($foreignTable, $uids, $language))
            : [];
    }

    // ── Shared helpers ───────────────────────────────────────────────────────

    /** Resolve the API config for a related table — see ApiRegistry::getRelated(). */
    public function resolveRelatedConfig(string $foreignTable, ApiDefinition $config, ?ColumnDefinition $columnDef = null): ?ApiDefinition
    {
        return $this->apiRegistry->getRelated($foreignTable, $config, $columnDef?->resourceName);
    }

    /**
     * A related record rendered without embedding: its IRI when the related resource
     * has an item endpoint, otherwise its uid — an IRI there would not dereference.
     */
    public static function reference(?ApiDefinition $relatedConfig, string $resourceName, int $uid, string $apiPrefix): string|int
    {
        return $relatedConfig?->isAddressable() === true
            ? $apiPrefix . '/' . $resourceName . '/' . $uid
            : $uid;
    }

    public static function buildStub(string $resourceName, string $resourceType, int $uid, string $apiPrefix = self::DEFAULT_API_PREFIX): array
    {
        return ['@id' => $apiPrefix . '/' . $resourceName . '/' . $uid, '@type' => $resourceType, 'uid' => $uid];
    }

    /** JSON-LD type of records from a table with no API registration, e.g. tx_news_domain_model_tag → TxNewsDomainModelTag. */
    public static function defaultResourceType(string $table): string
    {
        return GeneralUtility::underscoredToUpperCamelCase($table);
    }

    /**
     * Synthesize a minimal default-mode config for a table with no API registration.
     * 'columns' => [] with no 'groups' key → isExplicitMode = false → all TCA columns exposed.
     * Without operations it has no endpoint, so its records serialize without an @id.
     */
    public static function buildDefaultConfig(string $foreignTable, ?ColumnDefinition $columnDef = null): ApiDefinition
    {
        return ApiDefinition::fromArray([
            'general' => [
                'table'        => $foreignTable,
                'resourceName' => $columnDef->resourceName ?? $foreignTable,
                'resourceType' => $columnDef->resourceType ?? self::defaultResourceType($foreignTable),
                'operations'   => [],
            ],
            'columns' => [],
        ]);
    }
}
