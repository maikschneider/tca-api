<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
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
        $foreignTable = $fieldObj->getConfiguration()['foreign_table'] ?? null;

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
            return $apiPrefix . '/' . $resourceName . '/' . $fkValue;
        }

        $relatedRow = $preloaded['rows'][$foreignTable][$fkValue]
            ?? $this->dataRepository->findById($foreignTable, $fkValue, $relatedConfig);

        if ($relatedRow === null) {
            return null;
        }

        return $serializer->serialize(
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

        if ($effectiveDepth <= 0 || $relatedConfig === null) {
            return array_map(fn (array $r) => $apiPrefix . '/' . $resourceName . '/' . (int)$r['uid'], $relatedRows);
        }

        $relatedBaseUrl = $apiPrefix . '/' . $relatedConfig->resourceName;
        $newVisited     = $visited + [$config->table . ':' . (int)$row['uid'] => true];

        $result = [];
        foreach ($relatedRows as $relatedRow) {
            $itemUid = (int)$relatedRow['uid'];

            if (isset($newVisited[$foreignTable . ':' . $itemUid])) {
                $result[] = $apiPrefix . '/' . $resourceName . '/' . $itemUid;
                continue;
            }

            $result[] = $serializer->serialize($relatedRow, $relatedConfig, $relatedBaseUrl, [], $preloaded, $effectiveDepth - 1, $newVisited, $operation);
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
        if (isset($preloaded['relations'][$column])) {
            return UidListParser::mapToRows(
                $preloaded['relations'][$column][$parentUid] ?? [],
                $preloaded['rows'][$foreignTable] ?? [],
            );
        }

        return $this->fetchHasManyRows($column, $field, $row, $parentTable);
    }

    /**
     * Fetch hasMany rows directly from DB for a single parent (slow path — not preloaded).
     */
    private function fetchHasManyRows(string $column, FieldTypeInterface&RelationalFieldTypeInterface $fieldObj, array $row, string $parentTable): array
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
                $hasOppositeField,
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
            );
            return $grouped[$parentUid] ?? [];
        }

        // UID list stored in parent row's own column (no MM, no foreign_field).
        $uids = GeneralUtility::intExplode(',', (string)($row[$column] ?? ''), true);
        return $uids !== []
            ? UidListParser::mapToRows($uids, $this->dataRepository->findByIds($foreignTable, $uids))
            : [];
    }

    // ── Shared helpers ───────────────────────────────────────────────────────

    /**
     * Resolve the API config for a related table.
     * For self-referential relations returns the current config to preserve embed definitions.
     * When $columnDef->resourceName is set, selects that specific ApiRegistry entry by name.
     */
    public function resolveRelatedConfig(string $foreignTable, ApiDefinition $config, ?ColumnDefinition $columnDef = null): ?ApiDefinition
    {
        if ($foreignTable === $config->table) {
            return $config;
        }

        if ($columnDef?->resourceName !== null) {
            return $this->apiRegistry->get($columnDef->resourceName);
        }

        return $this->apiRegistry->getByTable($foreignTable);
    }

    public static function buildStub(string $resourceName, string $resourceType, int $uid, string $apiPrefix = self::DEFAULT_API_PREFIX): array
    {
        return ['@id' => $apiPrefix . '/' . $resourceName . '/' . $uid, '@type' => $resourceType, 'uid' => $uid];
    }

    /**
     * Synthesize a minimal default-mode config for a table with no API registration.
     * 'columns' => [] with no 'groups' key → isExplicitMode = false → all TCA columns exposed.
     */
    public static function buildDefaultConfig(string $foreignTable, ?ColumnDefinition $columnDef = null): ApiDefinition
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
}
