<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
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
        private DataRepository $dataRepository
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
        $allowedTables = GeneralUtility::trimExplode(',', $fieldConfig['allowed'] ?? '', true);

        if ($allowedTables === []) {
            return [];
        }

        if (count($allowedTables) === 1) {
            return $this->serializeSingleTableGroup($column, $fieldConfig, $columnDef, $config, $row, $preloaded, $effectiveDepth, $visited, $allowedTables[0], $operation, $apiPrefix, $relationSerializer, $serializer);
        }

        return $this->serializeMultiTableGroup($column, $columnDef, $config, $row, $apiPrefix, $relationSerializer);
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
            ? $relationSerializer->serializeHasManyFromRows($foreignTable, $columnDef, $config, $row, $relatedRows, $preloaded, $effectiveDepth, $visited, $operation, $apiPrefix, $serializer)
            : [];
    }

    /** Multiple allowed tables: parse "tablename_uid" prefix format and return IRI strings. */
    private function serializeMultiTableGroup(
        string $column,
        ColumnDefinition $columnDef,
        ApiDefinition $config,
        array $row,
        string $apiPrefix,
        RelationSerializer $relationSerializer,
    ): array {
        $items = $this->parseMultiTableGroupValues(trim((string)($row[$column] ?? '')));

        return array_map(function (array $item) use ($columnDef, $config, $apiPrefix, $relationSerializer): string {
            $relatedConfig = $relationSerializer->resolveRelatedConfig($item['table'], $config);
            $resourceName  = $columnDef->resourceName ?? ($relatedConfig->resourceName ?? $item['table']);
            return $apiPrefix . '/' . $resourceName . '/' . $item['uid'];
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
}
