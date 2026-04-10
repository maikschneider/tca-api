<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessorInterface;
use MaikSchneider\TcaApi\Serializer\FileProcessing\ImageProcessor;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Schema\Field\FileFieldType;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
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
 *   (no 'embed' key)            — return shallow stub {@ id, @type, uid}  [default]
 */
class ResourceSerializer
{
    public function __construct(
        private readonly TcaSchemaFactory $schemaFactory,
        private readonly DataRepository $dataRepository,
        private readonly FileRepository $fileRepository,
    ) {
    }

    /**
     * Serialize a single raw DB row.
     *
     * @param array $preloaded   ['hasOne' => [foreignTable => [uid => row]], 'hasMany' => [column => [parentUid => [rows]]]]
     * @param int   $remainingDepth  -1 = top level (use per-column embed config);
     *                               ≥0 = recursive budget from parent embed
     * @param array $visited     ['table:uid' => true] cycle-prevention guard
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
        $table = $config['general']['table'];
        $uid   = (int)$row['uid'];
        $schema = $this->schemaFactory->get($table);

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
                $processor = $this->resolveFileProcessor($columnConfig);
                $fileRefs  = $this->fileRepository->findByRelation($table, $column, $uid);

                // type=file always has foreign_field set (by TcaPreparation), making RelationshipType=OneToMany
                // and hasOne() always false. For single-file fields we check maxitems directly.
                if (($field->getConfiguration()['maxitems'] ?? 0) === 1) {
                    $result[$column] = isset($fileRefs[0])
                        ? $processor->process($fileRefs[0], $columnConfig)
                        : null;
                } else {
                    $result[$column] = array_map(
                        fn ($ref) => $processor->process($ref, $columnConfig),
                        $fileRefs,
                    );
                }
                continue;
            }

            if ($field instanceof RelationalFieldTypeInterface) {
                if ($field->getRelationshipType()->hasOne()) {
                    $propertyName = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;
                    $result[$propertyName] = $this->serializeHasOne(
                        $column,
                        $columnConfig,
                        $config,
                        $row,
                        $field,
                        $preloaded,
                        $remainingDepth,
                        $visited,
                    );
                } else {
                    $relatedRows = $preloaded['hasMany'][$column][$uid] ?? $this->fetchHasManyRows($field, $row);

                    $effectiveDepth = $remainingDepth >= 0
                        ? $remainingDepth
                        : $this->resolveEmbedDepth($columnConfig);

                    $result[$column] = $relatedRows !== []
                        ? $this->serializeHasManyFromRows(
                            $columnConfig,
                            $config,
                            $field,
                            $row,
                            $relatedRows,
                            $preloaded,
                            $effectiveDepth,
                            $visited,
                        )
                        : [];
                }
            } else {
                $result[$column] = $this->applyColumnProcessor(
                    $row[$column] ?? null,
                    $columnConfig,
                    $result,
                    $row,
                );
            }
        }

        foreach ($config['virtualProperties'] ?? [] as $name => $virtualProperty) {
            if (isset($virtualProperty['processor'])) {
                $result[$name] = $this->applyColumnProcessor(null, $virtualProperty, $result, $row);
            } else {
                [$class, $method] = $virtualProperty['callback'];
                $result[$name] = GeneralUtility::makeInstance($class)->$method($result, $row);
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

        $effectiveDepth = $remainingDepth >= 0
            ? $remainingDepth
            : $this->resolveEmbedDepth($columnConfig);

        // For self-referential relations use the current config directly so that embed column
        // definitions (e.g. parent_id with embed:true on an article resource) are preserved
        // through recursive calls instead of falling back to a different ApiRegistry entry.
        $relatedConfig = $this->resolveRelatedConfig($foreignTable, $config);
        $resourceName  = $columnConfig['resourceName'] ?? ($relatedConfig !== null ? $relatedConfig['general']['resourceName'] : $foreignTable);
        $resourceType  = $columnConfig['resourceType'] ?? ($relatedConfig !== null ? $relatedConfig['general']['resourceType'] : $foreignTable);

        if ($effectiveDepth <= 0) {
            return $this->buildStub($resourceName, $resourceType, $fkValue);
        }

        $visitKey = $foreignTable . ':' . $fkValue;

        if (isset($visited[$visitKey]) || $relatedConfig === null) {
            return $this->buildStub($resourceName, $resourceType, $fkValue);
        }

        // Get or fetch the related row
        $relatedRow = $preloaded['hasOne'][$foreignTable][$fkValue]
            ?? $this->dataRepository->findById($foreignTable, $fkValue, []);

        if ($relatedRow === null) {
            return null;
        }

        $currentKey     = $config['general']['table'] . ':' . (int)$row['uid'];
        $newVisited     = $visited + [$currentKey => true];
        $relatedBaseUrl = '/_api/' . $relatedConfig['general']['resourceName'];

        return $this->serialize(
            $relatedRow,
            $relatedConfig,
            $relatedBaseUrl,
            [],
            $preloaded,
            $effectiveDepth - 1,
            $newVisited,
        );
    }

    /**
     * Fast path: serialize a hasMany relation from raw rows (preloaded or freshly fetched).
     *
     * Handles depth=0 (shallow stubs) and depth>0 (recursive full embed).
     * Cycle detection uses the parent row's uid.
     */
    private function serializeHasManyFromRows(
        array $columnConfig,
        array $config,
        RelationalFieldTypeInterface $fieldObj,
        array $row,
        array $relatedRows,
        array $preloaded,
        int $effectiveDepth,
        array $visited,
    ): array {
        if ($relatedRows === []) {
            return [];
        }

        $foreignTable = $fieldObj->getConfiguration()['foreign_table'] ?? null;
        if ($foreignTable === null) {
            return [];
        }

        $relatedConfig = $this->resolveRelatedConfig($foreignTable, $config);

        $resourceName = $columnConfig['resourceName']
            ?? ($relatedConfig !== null ? $relatedConfig['general']['resourceName'] : $foreignTable);
        $resourceType = $columnConfig['resourceType']
            ?? ($relatedConfig !== null ? $relatedConfig['general']['resourceType'] : $foreignTable);

        if ($effectiveDepth <= 0 || $relatedConfig === null) {
            return array_map(fn (array $r) => $this->buildStub($resourceName, $resourceType, (int)$r['uid']), $relatedRows);
        }

        $relatedBaseUrl = '/_api/' . $relatedConfig['general']['resourceName'];
        $currentKey     = $config['general']['table'] . ':' . (int)$row['uid'];
        $newVisited     = $visited + [$currentKey => true];

        $result = [];
        foreach ($relatedRows as $relatedRow) {
            $itemUid  = (int)$relatedRow['uid'];
            $visitKey = $foreignTable . ':' . $itemUid;

            if (isset($newVisited[$visitKey])) {
                $result[] = $this->buildStub($resourceName, $resourceType, $itemUid);
                continue;
            }

            $result[] = $this->serialize(
                $relatedRow,
                $relatedConfig,
                $relatedBaseUrl,
                [],
                $preloaded,
                $effectiveDepth - 1,
                $newVisited,
            );
        }

        return $result;
    }

    /**
     * Fetch hasMany related rows directly from the DB for a single parent row.
     * Used as the slow path when a column was not bulk-preloaded by EmbedPreloader.
     */
    private function fetchHasManyRows(RelationalFieldTypeInterface $fieldObj, array $row): array
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
            $grouped = $this->dataRepository->findHasManyByForeignField(
                $foreignTable,
                $fieldConfig['foreign_field'],
                [$parentUid],
            );
            return $grouped[$parentUid] ?? [];
        }

        return [];
    }

    private function buildStub(string $resourceName, string $resourceType, int $uid): array
    {
        return ['@id' => '/_api/' . $resourceName . '/' . $uid, '@type' => $resourceType, 'uid' => $uid];
    }

    /**
     * Resolve the embed depth for a column config.
     * Returns 0 when no embed configured.
     */
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
     * For self-referential relations, returns the current config to preserve embed definitions.
     */
    private function resolveRelatedConfig(string $foreignTable, array $config): ?array
    {
        return $foreignTable === $config['general']['table']
            ? $config
            : ApiRegistry::getByTable($foreignTable);
    }
}
