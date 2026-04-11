<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Utility\UidListParser;
use TYPO3\CMS\Core\Schema\Field\FileFieldType;
use TYPO3\CMS\Core\Schema\Field\GroupFieldType;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Bulk-preloads all related rows for embed-configured columns in a fixed number of DB queries.
 *
 * Returns a unified map:
 *   [
 *     'hasOne'  => [foreignTable => [uid => row]],
 *     'hasMany' => [column => [parentUid => [row, ...]]],
 *   ]
 *
 * This eliminates N+1 queries during serialization: ResourceSerializer reads from this map
 * instead of issuing per-row queries.
 */
class EmbedPreloader
{
    public function __construct(
        private readonly DataRepository $dataRepository,
        private readonly TcaSchemaFactory $schemaFactory,
    ) {
    }

    public function preload(array $rows, array $config): array
    {
        $preloaded = ['hasOne' => [], 'hasMany' => []];

        if ($rows === []) {
            return $preloaded;
        }

        $table  = $config['general']['table'];
        $schema = $this->schemaFactory->get($table);

        // Collect hasOne FK UIDs across all columns before querying, so multiple columns
        // pointing to the same foreignTable are combined into one findByIds call.
        $hasOneUidsByTable = [];

        foreach ($config['columns'] as $column => $columnConfig) {
            if (!($columnConfig['readable'] ?? false)) {
                continue;
            }

            $embed = $columnConfig['embed'] ?? null;
            if ($embed === null || $embed === false) {
                continue;
            }

            if (!$schema->hasField($column)) {
                continue;
            }

            $field = $schema->getField($column);

            if (!($field instanceof RelationalFieldTypeInterface)) {
                continue;
            }

            if ($field instanceof FileFieldType) {
                continue;
            }

            $fieldConfig = $field->getConfiguration();

            // type=group: preload single-table groups like a UID list; skip multi-table (slow path).
            if ($field instanceof GroupFieldType) {
                $allowedTables = GeneralUtility::trimExplode(',', $fieldConfig['allowed'] ?? '', true);
                if (count($allowedTables) !== 1) {
                    continue; // multi-table group: use per-row slow path
                }

                $foreignTable = $allowedTables[0];
                $mmTable      = $fieldConfig['MM'] ?? null;

                if ($mmTable !== null) {
                    $parentUids = array_values(array_filter(
                        array_unique(array_map(fn (array $row) => (int)($row['uid'] ?? 0), $rows)),
                        fn (int $uid) => $uid > 0,
                    ));

                    if ($parentUids !== []) {
                        $hasOppositeField = isset($fieldConfig['MM_opposite_field']);
                        $preloaded['hasMany'][$column] = $this->dataRepository->findHasManyByMM(
                            $foreignTable,
                            $parentUids,
                            $mmTable,
                            $hasOppositeField ? 'uid_foreign' : 'uid_local',
                            $hasOppositeField ? 'uid_local'  : 'uid_foreign',
                            $fieldConfig['MM_match_fields'] ?? [],
                        );
                    }
                } else {
                    $preloaded['hasMany'][$column] = $this->preloadUidListHasMany($column, $foreignTable, $rows);
                }
                continue;
            }

            $foreignTable = $fieldConfig['foreign_table'] ?? null;
            if ($foreignTable === null) {
                continue;
            }

            if ($field->getRelationshipType()->hasOne()) {
                foreach ($rows as $row) {
                    $fk = (int)($row[$column] ?? 0);
                    if ($fk > 0) {
                        $hasOneUidsByTable[$foreignTable][$fk] = true;
                    }
                }
            } else {
                $parentUids = array_values(array_filter(
                    array_unique(array_map(fn (array $row) => (int)($row['uid'] ?? 0), $rows)),
                    fn (int $uid) => $uid > 0,
                ));

                if ($parentUids === []) {
                    continue;
                }

                $mmTable = $fieldConfig['MM'] ?? null;

                if ($mmTable !== null) {
                    $hasOppositeField = isset($fieldConfig['MM_opposite_field']);
                    $preloaded['hasMany'][$column] = $this->dataRepository->findHasManyByMM(
                        $foreignTable,
                        $parentUids,
                        $mmTable,
                        $hasOppositeField ? 'uid_foreign' : 'uid_local',
                        $hasOppositeField ? 'uid_local'  : 'uid_foreign',
                        $fieldConfig['MM_match_fields'] ?? [],
                    );
                } elseif (isset($fieldConfig['foreign_field'])) {
                    $preloaded['hasMany'][$column] = $this->dataRepository->findHasManyByForeignField(
                        $foreignTable,
                        $fieldConfig['foreign_field'],
                        $parentUids,
                    );
                } else {
                    // UID list stored in parent row's own column (no MM, no foreign_field).
                    // Bulk-fetch all referenced UIDs in one query, then group by parent.
                    $preloaded['hasMany'][$column] = $this->preloadUidListHasMany($column, $foreignTable, $rows);
                }
            }
        }

        foreach ($hasOneUidsByTable as $foreignTable => $uidSet) {
            $preloaded['hasOne'][$foreignTable] = $this->dataRepository->findByIds($foreignTable, array_keys($uidSet));
        }

        return $preloaded;
    }

    /**
     * Bulk-fetch a UID-list hasMany column (UIDs stored comma-separated in the parent row).
     * Returns [parentUid => [rows]] preserving the stored UID order.
     */
    private function preloadUidListHasMany(string $column, string $foreignTable, array $rows): array
    {
        $allUids      = [];
        $parentUidMap = [];

        foreach ($rows as $row) {
            $parentUid = (int)$row['uid'];
            $uids      = UidListParser::parse((string)($row[$column] ?? ''));

            $parentUidMap[$parentUid] = $uids;
            foreach ($uids as $uid) {
                $allUids[$uid] = true;
            }
        }

        $fetched = $allUids !== []
            ? $this->dataRepository->findByIds($foreignTable, array_keys($allUids))
            : [];

        $result = [];
        foreach ($parentUidMap as $parentUid => $uids) {
            $result[$parentUid] = UidListParser::mapToRows($uids, $fetched);
        }

        return $result;
    }
}
