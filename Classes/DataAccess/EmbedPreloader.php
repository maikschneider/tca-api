<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
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
 *     'rows'      => [foreignTable => [uid => row]],
 *     'relations' => [column => [parentUid => [uid, ...]]],
 *   ]
 *
 * `rows` is a flat pool of all fetched rows — both hasOne and hasMany resolve from it.
 * `relations` stores the ordered UID mapping per parent for hasMany columns.
 *
 * This eliminates N+1 queries during serialization: ResourceSerializer reads from this map
 * instead of issuing per-row queries.
 */
final class EmbedPreloader
{
    public function __construct(
        private readonly DataRepository $dataRepository,
        private readonly TcaSchemaFactory $schemaFactory,
    ) {
    }

    public function preload(array $rows, ApiDefinition $config): array
    {
        $preloaded = ['rows' => [], 'relations' => []];

        if ($rows === []) {
            return $preloaded;
        }

        $schema = $this->schemaFactory->get($config->table);

        // Collect UIDs for all UID-based fetches (hasOne FKs + UID-list hasMany + group UID-list).
        // Multiple columns pointing to the same foreignTable are combined into one findByIds call.
        $uidsByTable = [];

        $parentUids = array_values(array_filter(
            array_unique(array_map(fn (array $row) => (int)($row['uid'] ?? 0), $rows)),
            fn (int $uid) => $uid > 0,
        ));

        foreach ($config->columns as $column => $columnDef) {
            if ($columnDef->embedDepth() === 0) {
                continue;
            }

            if ($config->isExplicitMode && !$columnDef->isReadable()) {
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

            // type=group: preload single-table groups like a UID list; multi-table uses prefixed format.
            if ($field instanceof GroupFieldType) {
                $allowedTables = GeneralUtility::trimExplode(',', $fieldConfig['allowed'] ?? '', true);

                if (count($allowedTables) === 1) {
                    $foreignTable = $allowedTables[0];
                    $mmTable      = $fieldConfig['MM'] ?? null;

                    if ($mmTable !== null) {
                        $this->preloadMm($preloaded, $column, $foreignTable, $fieldConfig, $parentUids);
                    } else {
                        $this->collectUidListRelations($preloaded, $uidsByTable, $column, $foreignTable, $rows);
                    }
                } elseif (count($allowedTables) > 1) {
                    $this->collectMultiTableGroupRelations($preloaded, $uidsByTable, $column, $rows, $allowedTables);
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
                        $uidsByTable[$foreignTable][$fk] = true;
                    }
                }
            } else {
                if ($parentUids === []) {
                    continue;
                }

                $mmTable = $fieldConfig['MM'] ?? null;

                if ($mmTable !== null) {
                    $this->preloadMm($preloaded, $column, $foreignTable, $fieldConfig, $parentUids);
                } elseif (isset($fieldConfig['foreign_field'])) {
                    $this->preloadForeignField(
                        $preloaded,
                        $column,
                        $foreignTable,
                        $fieldConfig['foreign_field'],
                        $parentUids,
                        $fieldConfig['foreign_table_field'] ?? null,
                        $config->table,
                        $fieldConfig['foreign_match_fields'] ?? [],
                    );
                } else {
                    $this->collectUidListRelations($preloaded, $uidsByTable, $column, $foreignTable, $rows);
                }
            }
        }

        // Single findByIds per foreignTable — covers hasOne FKs + UID-list hasMany + group UID-list.
        foreach ($uidsByTable as $foreignTable => $uidSet) {
            $fetched = $this->dataRepository->findByIds($foreignTable, array_keys($uidSet));
            $preloaded['rows'][$foreignTable] = ($preloaded['rows'][$foreignTable] ?? []) + $fetched;
        }

        return $preloaded;
    }

    /**
     * Preload a hasMany MM relation: fetch rows via JOIN, store in pool + relations.
     */
    private function preloadMm(array &$preloaded, string $column, string $foreignTable, array $fieldConfig, array $parentUids): void
    {
        $mmTable          = $fieldConfig['MM'];
        $hasOppositeField = isset($fieldConfig['MM_opposite_field']);

        $grouped = $this->dataRepository->findHasManyByMM(
            $foreignTable,
            $parentUids,
            $mmTable,
            $hasOppositeField ? 'uid_foreign' : 'uid_local',
            $hasOppositeField ? 'uid_local'  : 'uid_foreign',
            $fieldConfig['MM_match_fields'] ?? [],
        );

        foreach ($grouped as $parentUid => $childRows) {
            $preloaded['relations'][$column][$parentUid] = array_map(fn (array $r) => (int)$r['uid'], $childRows);
            foreach ($childRows as $childRow) {
                $preloaded['rows'][$foreignTable][(int)$childRow['uid']] = $childRow;
            }
        }
    }

    /**
     * Preload a hasMany foreignField relation: fetch rows, store in pool + relations.
     */
    private function preloadForeignField(array &$preloaded, string $column, string $foreignTable, string $foreignField, array $parentUids, ?string $foreignTableField = null, ?string $parentTable = null, array $foreignMatchFields = []): void
    {
        $grouped = $this->dataRepository->findHasManyByForeignField(
            $foreignTable,
            $foreignField,
            $parentUids,
            $foreignTableField,
            $parentTable,
            $foreignMatchFields,
        );

        foreach ($grouped as $parentUid => $childRows) {
            $preloaded['relations'][$column][$parentUid] = array_map(fn (array $r) => (int)$r['uid'], $childRows);
            foreach ($childRows as $childRow) {
                $preloaded['rows'][$foreignTable][(int)$childRow['uid']] = $childRow;
            }
        }
    }

    /**
     * Collect UID-list hasMany UIDs for deferred bulk fetch, and store parent→child UID mappings.
     */
    private function collectUidListRelations(array &$preloaded, array &$uidsByTable, string $column, string $foreignTable, array $rows): void
    {
        foreach ($rows as $row) {
            $parentUid = (int)$row['uid'];
            $uids      = GeneralUtility::intExplode(',', (string)($row[$column] ?? ''), true);

            $preloaded['relations'][$column][$parentUid] = $uids;
            foreach ($uids as $uid) {
                $uidsByTable[$foreignTable][$uid] = true;
            }
        }
    }

    /**
     * Collect multi-table group (prefixed "tablename_uid") UIDs for deferred bulk fetch.
     * Stores parent→child mappings as [{table, uid}] items preserving order.
     */
    private function collectMultiTableGroupRelations(array &$preloaded, array &$uidsByTable, string $column, array $rows, array $allowedTables): void
    {
        foreach ($rows as $row) {
            $parentUid = (int)$row['uid'];
            $raw       = trim((string)($row[$column] ?? ''));
            $items     = [];

            if ($raw !== '') {
                foreach (GeneralUtility::trimExplode(',', $raw, true) as $entry) {
                    $pos = strrpos($entry, '_');
                    if ($pos === false) {
                        continue;
                    }
                    $table = substr($entry, 0, $pos);
                    $uid   = (int)substr($entry, $pos + 1);
                    if ($uid > 0 && $table !== '' && in_array($table, $allowedTables, true)) {
                        $items[] = ['table' => $table, 'uid' => $uid];
                        $uidsByTable[$table][$uid] = true;
                    }
                }
            }

            $preloaded['multiTableRelations'][$column][$parentUid] = $items;
        }
    }
}
