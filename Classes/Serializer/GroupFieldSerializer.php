<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Tca\GroupAllowedResolver;
use MaikSchneider\TcaApi\Utility\UidListParser;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Serializes type=group TCA fields (single-table and multi-table variants).
 *
 * Single-table groups resolve their rows via the preloaded pool, MM join,
 * or UID-list fallback, then delegate to RelationSerializer for depth
 * handling. Multi-table groups parse the "tablename_uid" prefix format
 * and return plain IRI strings.
 */
final readonly class GroupFieldSerializer
{
    public function __construct(
        private DataRepository $dataRepository,
        private GroupAllowedResolver $groupAllowedResolver,
    ) {
    }

    /**
     * Serialize a type=group hasMany field.
     * Dispatches to single-table (UID list or MM) or multi-table (prefix-format stubs) path.
     */
    public function serialize(
        string $column,
        array $fieldConfig,
        ColumnDefinition $columnDef,
        ApiDefinition $config,
        array $row,
        array $preloaded,
        int $effectiveDepth,
        array $visited,
        string $operation,
        string $apiPrefix,
        RelationSerializer $relationSerializer,
        ResourceSerializer $serializer,
    ): array {
        // Wildcard reverse-side MM: allowed='*' with MM + MM_oppositeUsage
        if ($this->groupAllowedResolver->isWildcard($fieldConfig)) {
            if (!isset($fieldConfig['MM'])) {
                return [];
            }

            return $this->serializeReverseMm($column, $fieldConfig, $columnDef, $config, $row, $preloaded, $effectiveDepth, $visited, $operation, $apiPrefix, $relationSerializer, $serializer);
        }

        $allowedTables = GeneralUtility::trimExplode(',', $fieldConfig['allowed'] ?? '', true);

        if ($allowedTables === []) {
            return [];
        }

        if (count($allowedTables) === 1) {
            return $this->serializeSingleTableGroup($column, $fieldConfig, $columnDef, $config, $row, $preloaded, $effectiveDepth, $visited, $allowedTables[0], $operation, $apiPrefix, $relationSerializer, $serializer);
        }

        return $this->serializeMultiTableGroup($column, $columnDef, $config, $row, $preloaded, $effectiveDepth, $visited, $operation, $apiPrefix, $relationSerializer, $serializer);
    }

    /**
     * Reverse-side MM (allowed='*' + MM_oppositeUsage): read from preloaded pool when available,
     * otherwise query findReverseMmRelations directly (cold path / unit tests).
     */
    private function serializeReverseMm(
        string $column,
        array $fieldConfig,
        ColumnDefinition $columnDef,
        ApiDefinition $config,
        array $row,
        array $preloaded,
        int $effectiveDepth,
        array $visited,
        string $operation,
        string $apiPrefix,
        RelationSerializer $relationSerializer,
        ResourceSerializer $serializer,
    ): array {
        $uid = (int)$row['uid'];

        if (isset($preloaded['multiTableRelations'][$column][$uid])) {
            $items = $preloaded['multiTableRelations'][$column][$uid];
        } else {
            $oppositeUsage = $this->groupAllowedResolver->resolveOppositeUsage($fieldConfig);
            if ($oppositeUsage === []) {
                return [];
            }

            $grouped = $this->dataRepository->findReverseMmRelations([$uid], $fieldConfig['MM'], $oppositeUsage, $fieldConfig['MM_match_fields'] ?? []);
            $items = $grouped[$uid] ?? [];
        }

        return $this->serializeMultiTableGroup($column, $columnDef, $config, $row, $preloaded, $effectiveDepth, $visited, $operation, $apiPrefix, $relationSerializer, $serializer, $items);
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
        string $operation,
        string $apiPrefix,
        RelationSerializer $relationSerializer,
        ResourceSerializer $serializer,
    ): array {
        $uid      = (int)$row['uid'];
        $mmTable  = $fieldConfig['MM'] ?? null;
        $language = $preloaded['__language'] ?? null;

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
                $language,
            );
            $relatedRows = $grouped[$uid] ?? [];
        } else {
            $uids        = GeneralUtility::intExplode(',', (string)($row[$column] ?? ''), true);
            $relatedRows = $uids !== []
                ? UidListParser::mapToRows($uids, $this->dataRepository->findByIdsWithOverlay($foreignTable, $uids, $language))
                : [];
        }

        return $relatedRows !== []
            ? $relationSerializer->serializeHasManyFromRows($foreignTable, $columnDef, $config, $row, $relatedRows, $preloaded, $effectiveDepth, $visited, $operation, $apiPrefix, $serializer)
            : [];
    }

    /** Multiple allowed tables: parse "tablename_uid" prefix format, with optional embedding. */
    private function serializeMultiTableGroup(
        string $column,
        ColumnDefinition $columnDef,
        ApiDefinition $config,
        array $row,
        array $preloaded,
        int $effectiveDepth,
        array $visited,
        string $operation,
        string $apiPrefix,
        RelationSerializer $relationSerializer,
        ResourceSerializer $serializer,
        ?array $items = null,
    ): array {
        $uid = (int)$row['uid'];

        $items ??= isset($preloaded['multiTableRelations'][$column][$uid])
            ? $preloaded['multiTableRelations'][$column][$uid]
            : $this->parseMultiTableGroupValues(trim((string)($row[$column] ?? '')));

        if ($items === []) {
            return [];
        }

        if ($effectiveDepth <= 0) {
            return array_map(function (array $item) use ($config, $apiPrefix, $relationSerializer): string {
                $relatedConfig = $relationSerializer->resolveRelatedConfig($item['table'], $config);
                $resourceName  = $relatedConfig->resourceName ?? $item['table'];
                return $apiPrefix . '/' . $resourceName . '/' . $item['uid'];
            }, $items);
        }

        $newVisited = $visited + [$config->table . ':' . $uid => true];
        $result     = [];

        // Group items by table for efficient row resolution from the preloaded pool
        $rowsByTable = [];
        $missingByTable = [];
        foreach ($items as $item) {
            $table = $item['table'];
            $itemUid = $item['uid'];
            if (isset($preloaded['rows'][$table][$itemUid])) {
                $rowsByTable[$table][$itemUid] = $preloaded['rows'][$table][$itemUid];
            } else {
                $missingByTable[$table][$itemUid] = true;
            }
        }

        // Bulk-fetch any missing rows per table
        $language = $preloaded['__language'] ?? null;
        foreach ($missingByTable as $table => $uidSet) {
            $fetched = $this->dataRepository->findByIdsWithOverlay($table, array_keys($uidSet), $language);
            foreach ($fetched as $fetchedUid => $fetchedRow) {
                $rowsByTable[$table][$fetchedUid] = $fetchedRow;
            }
        }

        foreach ($items as $item) {
            $table   = $item['table'];
            $itemUid = $item['uid'];

            $relatedConfig = $relationSerializer->resolveRelatedConfig($table, $config);
            if ($relatedConfig === null) {
                $relatedConfig = RelationSerializer::buildDefaultConfig($table);
            }
            $resourceName = $relatedConfig->resourceName;

            if (isset($newVisited[$table . ':' . $itemUid])) {
                $result[] = $apiPrefix . '/' . $resourceName . '/' . $itemUid;
                continue;
            }

            $relatedRow = $rowsByTable[$table][$itemUid] ?? null;
            if ($relatedRow === null) {
                continue;
            }

            $result[] = $serializer->serialize(
                $relatedRow,
                $relatedConfig,
                $apiPrefix . '/' . $resourceName,
                [],
                $preloaded,
                $effectiveDepth - 1,
                $newVisited,
                $operation,
            );
        }

        return $result;
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
}
