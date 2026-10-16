<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Serializer\RelationSerializer;
use MaikSchneider\TcaApi\Tca\GroupAllowedResolver;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use TYPO3\CMS\Core\Schema\Field\FileFieldType;
use TYPO3\CMS\Core\Schema\Field\GroupFieldType;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Bulk-preloads the relations of a page of records in a fixed number of DB queries.
 *
 * Returns a unified map:
 *   [
 *     'rows'                => [foreignTable => [uid => row]],
 *     'relations'           => [table => [column => [parentUid => [uid, ...]]]],
 *     'multiTableRelations' => [table => [column => [parentUid => [['table' => …, 'uid' => …], ...]]]],
 *     'files'               => [table => PreloadedFileReferences],
 *   ]
 *
 * `rows` is a flat pool of all fetched rows — both hasOne and hasMany resolve from it.
 * `relations` and `multiTableRelations` hold an entry for every record they cover,
 * empty or not; a record without an entry was not preloaded and the serializer
 * resolves it itself.
 *
 * Every relation column the serializer will output is covered, embedded or rendered
 * as IRIs, and so are the records embedded through it, down to the embed depth the
 * serializer will use. ResourceSerializer reads from this map instead of issuing
 * per-row queries.
 */
final class EmbedPreloader
{
    public function __construct(
        private readonly DataRepository $dataRepository,
        private readonly TcaSchemaFactory $schemaFactory,
        private readonly GroupAllowedResolver $groupAllowedResolver,
        private readonly FileReferencePreloader $fileReferencePreloader,
        private readonly ApiRegistry $apiRegistry,
    ) {
    }

    /**
     * @param array  $fields    Partial-response column list, as passed to the serializer
     * @param string $operation Operation the serializer will run for, as passed to it
     */
    public function preload(array $rows, ApiDefinition $config, ?SiteLanguage $language = null, array $fields = [], string $operation = ''): array
    {
        $preloaded = [
            'rows'                => [],
            'relations'           => [],
            'multiTableRelations' => [],
            'files'               => [],
            // Serializer slow paths read the language from here.
            '__language'          => ($config->languageMode !== 'ignore') ? $language : null,
        ];

        $this->preloadRows($preloaded, $rows, $config, -1, $fields, $operation);

        return $preloaded;
    }

    /**
     * Preload one level of records and recurse into the records it embeds.
     *
     * $depth mirrors ResourceSerializer::serialize(): -1 at the top level, where each
     * column embeds as deep as its own config says, and the remaining budget below
     * it, which then applies to every relation column of the embedded record.
     */
    private function preloadRows(array &$preloaded, array $rows, ApiDefinition $config, int $depth, array $fields, string $operation): void
    {
        $parentUids = array_values(array_filter(
            array_unique(array_map(fn (array $row) => (int)($row['uid'] ?? 0), $rows)),
            fn (int $uid) => $uid > 0,
        ));

        if ($parentUids === []) {
            return;
        }

        $table    = $config->table;
        $schema   = $this->schemaFactory->get($table);
        $language = $preloaded['__language'];

        $files = $this->fileReferencePreloader->preload($config, $parentUids);
        $preloaded['files'][$table] = isset($preloaded['files'][$table]) ? $preloaded['files'][$table]->merge($files) : $files;

        // Collect UIDs for all UID-based fetches (hasOne FKs + UID-list hasMany + group UID-list).
        // Multiple columns pointing to the same foreignTable are combined into one findByIds call.
        $uidsByTable = [];

        // Records embedded through each column: [column definition, remaining depth, [table => [uid => true]], polymorphic]
        $embeds = [];

        foreach ($this->outputColumns($config, $fields, $operation) as $column => $columnDef) {
            if (!$schema->hasField($column)) {
                continue;
            }

            $field = $schema->getField($column);

            if (!($field instanceof RelationalFieldTypeInterface)) {
                continue;
            }

            // File columns are resolved by the FileReferencePreloader above.
            if ($field instanceof FileFieldType) {
                continue;
            }

            $columnDepth = $depth >= 0 ? $depth : $columnDef->embedDepth();
            $embedded    = $columnDepth > 0;
            $fieldConfig = $field->getConfiguration();
            $polymorphic = false;

            // type=group: preload single-table groups like a UID list; multi-table uses prefixed format.
            if ($field instanceof GroupFieldType) {
                // Wildcard reverse-side MM: allowed='*' with MM + MM_oppositeUsage
                if ($this->groupAllowedResolver->isWildcard($fieldConfig) && isset($fieldConfig['MM'])) {
                    $children    = $this->preloadReverseMm($preloaded, $table, $column, $fieldConfig, $parentUids);
                    $polymorphic = true;
                } else {
                    $allowedTables = GeneralUtility::trimExplode(',', $fieldConfig['allowed'] ?? '', true);

                    if (count($allowedTables) === 1) {
                        $children = isset($fieldConfig['MM'])
                            ? $this->preloadMm($preloaded, $table, $column, $allowedTables[0], $fieldConfig, $parentUids, $language)
                            : $this->collectUidListRelations($preloaded, $table, $column, $allowedTables[0], $rows);
                    } elseif (count($allowedTables) > 1) {
                        $children    = $this->collectMultiTableGroupRelations($preloaded, $table, $column, $rows, $allowedTables);
                        $polymorphic = true;
                    } else {
                        continue;
                    }
                }
            } else {
                $foreignTable = $fieldConfig['foreign_table'] ?? null;
                if ($foreignTable === null) {
                    continue;
                }

                if ($field->getRelationshipType()->hasOne()) {
                    // Rendered as an IRI straight from the foreign key — nothing to fetch.
                    if (!$embedded) {
                        continue;
                    }

                    $children = [];
                    foreach ($rows as $row) {
                        $fk = (int)($row[$column] ?? 0);
                        if ($fk > 0) {
                            $children[$foreignTable][$fk] = true;
                        }
                    }
                } elseif (isset($fieldConfig['MM'])) {
                    $children = $this->preloadMm($preloaded, $table, $column, $foreignTable, $fieldConfig, $parentUids, $language);
                } elseif (isset($fieldConfig['foreign_field'])) {
                    $children = $this->preloadForeignField(
                        $preloaded,
                        $table,
                        $column,
                        $foreignTable,
                        $fieldConfig['foreign_field'],
                        $parentUids,
                        $fieldConfig['foreign_table_field'] ?? null,
                        $fieldConfig['foreign_match_fields'] ?? [],
                        $language,
                    );
                } else {
                    $children = $this->collectUidListRelations($preloaded, $table, $column, $foreignTable, $rows);
                }
            }

            // Polymorphic groups render IRIs from the stored items alone; their rows
            // are only needed when embedded. Every other row set is fetched either way
            // because the serializer drops references to records that do not resolve.
            if ($embedded || !$polymorphic) {
                foreach ($children as $childTable => $uidSet) {
                    $uidsByTable[$childTable] = ($uidsByTable[$childTable] ?? []) + $uidSet;
                }
            }

            if ($embedded && $children !== []) {
                $embeds[] = [$columnDef, $columnDepth - 1, $children, $polymorphic];
            }
        }

        // Single findByIdsWithOverlay per foreignTable — covers hasOne FKs + UID-list hasMany + group UID-list.
        foreach ($uidsByTable as $foreignTable => $uidSet) {
            $missing = array_diff_key($uidSet, $preloaded['rows'][$foreignTable] ?? []);
            if ($missing === []) {
                continue;
            }

            $fetched = $this->dataRepository->findByIdsWithOverlay($foreignTable, array_keys($missing), $language);
            $preloaded['rows'][$foreignTable] = ($preloaded['rows'][$foreignTable] ?? []) + $fetched;
        }

        foreach ($embeds as [$columnDef, $childDepth, $children, $polymorphic]) {
            foreach ($children as $childTable => $uidSet) {
                // Same config resolution as RelationSerializer and GroupFieldSerializer.
                $childConfig = $polymorphic
                    ? ($this->apiRegistry->getRelated($childTable, $config) ?? RelationSerializer::buildDefaultConfig($childTable))
                    : ($this->apiRegistry->getRelated($childTable, $config, $columnDef->resourceName) ?? RelationSerializer::buildDefaultConfig($childTable, $columnDef));

                $childRows = array_values(array_intersect_key($preloaded['rows'][$childTable] ?? [], $uidSet));

                $this->preloadRows($preloaded, $childRows, $childConfig, $childDepth, $columnDef->embedColumns(), $operation);
            }
        }
    }

    /**
     * The columns ResourceSerializer::serialize() will output for this config.
     *
     * @return array<string, ColumnDefinition>
     */
    private function outputColumns(ApiDefinition $config, array $fields, string $operation): array
    {
        if ($config->isExplicitMode) {
            $columns = array_filter($config->columns, static fn (ColumnDefinition $def) => $def->isReadable($operation));
        } else {
            $columns = [];
            foreach (TcaColumnDiscovery::getExposableColumnNames($config->table) as $column) {
                $columns[$column] = $config->columns[$column] ?? new ColumnDefinition(groups: null);
            }
        }

        return $fields === [] ? $columns : array_intersect_key($columns, array_flip($fields));
    }

    /**
     * Preload a reverse-side MM relation (allowed='*' + MM_oppositeUsage).
     * Queries the MM table for uid_local IN (...) filtered by MM_oppositeUsage table+field pairs
     * and stores the results as multiTableRelations.
     *
     * @param  list<int>                       $parentUids
     * @return array<string, array<int, true>> related UIDs by table
     */
    private function preloadReverseMm(array &$preloaded, string $table, string $column, array $fieldConfig, array $parentUids): array
    {
        $oppositeUsage = $this->groupAllowedResolver->resolveOppositeUsage($fieldConfig);
        if ($oppositeUsage === []) {
            return [];
        }

        $grouped = $this->dataRepository->findReverseMmRelations(
            $parentUids,
            $fieldConfig['MM'],
            $oppositeUsage,
            $fieldConfig['MM_match_fields'] ?? [],
        );

        $children = [];
        foreach ($parentUids as $parentUid) {
            $items = $grouped[$parentUid] ?? [];
            $preloaded['multiTableRelations'][$table][$column][$parentUid] = $items;
            foreach ($items as $item) {
                $children[$item['table']][$item['uid']] = true;
            }
        }

        return $children;
    }

    /**
     * Preload a hasMany MM relation: fetch rows via JOIN, store in pool + relations.
     *
     * @return array<string, array<int, true>> related UIDs by table
     */
    private function preloadMm(array &$preloaded, string $table, string $column, string $foreignTable, array $fieldConfig, array $parentUids, ?SiteLanguage $language): array
    {
        $hasOppositeField = isset($fieldConfig['MM_opposite_field']);

        $grouped = $this->dataRepository->findHasManyByMM(
            $foreignTable,
            $parentUids,
            $fieldConfig['MM'],
            $hasOppositeField ? 'uid_foreign' : 'uid_local',
            $hasOppositeField ? 'uid_local'  : 'uid_foreign',
            $fieldConfig['MM_match_fields'] ?? [],
            $language,
        );

        return $this->storeRelatedRows($preloaded, $table, $column, $foreignTable, $parentUids, $grouped);
    }

    /**
     * Preload a hasMany foreignField relation: fetch rows, store in pool + relations.
     *
     * @return array<string, array<int, true>> related UIDs by table
     */
    private function preloadForeignField(array &$preloaded, string $table, string $column, string $foreignTable, string $foreignField, array $parentUids, ?string $foreignTableField, array $foreignMatchFields, ?SiteLanguage $language): array
    {
        $grouped = $this->dataRepository->findHasManyByForeignField(
            $foreignTable,
            $foreignField,
            $parentUids,
            $foreignTableField,
            $table,
            $foreignMatchFields,
            $language,
        );

        return $this->storeRelatedRows($preloaded, $table, $column, $foreignTable, $parentUids, $grouped);
    }

    /**
     * @param  array<int, list<array<string, mixed>>> $grouped parent uid => related rows
     * @return array<string, array<int, true>>         related UIDs by table
     */
    private function storeRelatedRows(array &$preloaded, string $table, string $column, string $foreignTable, array $parentUids, array $grouped): array
    {
        $children = [];
        foreach ($parentUids as $parentUid) {
            $uids = [];
            foreach ($grouped[$parentUid] ?? [] as $childRow) {
                $uid    = (int)$childRow['uid'];
                $uids[] = $uid;
                $preloaded['rows'][$foreignTable][$uid] = $childRow;
                $children[$foreignTable][$uid] = true;
            }
            $preloaded['relations'][$table][$column][$parentUid] = $uids;
        }

        return $children;
    }

    /**
     * Store UID-list hasMany parent→child UID mappings; the rows are fetched in bulk afterwards.
     *
     * @return array<string, array<int, true>> related UIDs by table
     */
    private function collectUidListRelations(array &$preloaded, string $table, string $column, string $foreignTable, array $rows): array
    {
        $children = [];
        foreach ($rows as $row) {
            $parentUid = (int)$row['uid'];
            $uids      = GeneralUtility::intExplode(',', (string)($row[$column] ?? ''), true);

            $preloaded['relations'][$table][$column][$parentUid] = $uids;
            foreach ($uids as $uid) {
                $children[$foreignTable][$uid] = true;
            }
        }

        return $children;
    }

    /**
     * Store multi-table group (prefixed "tablename_uid") parent→child mappings as
     * [{table, uid}] items, preserving order.
     *
     * @return array<string, array<int, true>> related UIDs by table
     */
    private function collectMultiTableGroupRelations(array &$preloaded, string $table, string $column, array $rows, array $allowedTables): array
    {
        $children = [];
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
                    $itemTable = substr($entry, 0, $pos);
                    $uid       = (int)substr($entry, $pos + 1);
                    if ($uid > 0 && $itemTable !== '' && in_array($itemTable, $allowedTables, true)) {
                        $items[] = ['table' => $itemTable, 'uid' => $uid];
                        $children[$itemTable][$uid] = true;
                    }
                }
            }

            $preloaded['multiTableRelations'][$table][$column][$parentUid] = $items;
        }

        return $children;
    }
}
