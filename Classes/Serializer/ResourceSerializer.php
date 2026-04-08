<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Serializes a TCA domain record to a Hydra JSON-LD array.
 *
 * Relation resolution is delegated to RecordFactory::createResolvedRecordFromDatabaseRow(),
 * which uses TYPO3's RecordFieldTransformer to lazily resolve any TCA-defined relation into
 * typed Record objects. The Schema API (TcaSchemaFactory) is used to introspect field types
 * and relationship cardinality — no direct TCA array access.
 *
 * Embed config (per column):
 *   'embed' => true              — embed full related record at depth 1
 *   'embed' => ['depth' => N]   — embed N levels deep
 *   (no 'embed' key)            — return shallow stub {@ id, @type, uid}  [default]
 *
 * The 'embed' approach supersedes the older 'inlineFields' pattern. Use 'embed' to include
 * full related records inline. 'inlineFields' is deprecated and should not be used in new configs.
 *
 * Note: RecordFactory and the Schema API are marked @internal in TYPO3 core.
 * They are used here intentionally as the canonical v13 record/schema layer.
 */
class ResourceSerializer
{
    public function __construct(
        private readonly RecordFactory $recordFactory,
        private readonly TcaSchemaFactory $schemaFactory,
        private readonly DataRepository $dataRepository,
    ) {
    }

    /**
     * Serialize a single raw DB row.
     *
     * @param array $prefetched  [foreignTable => [uid => row]] pre-fetched related records
     * @param int   $remainingDepth  -1 = top level (use per-column embed config);
     *                               ≥0 = recursive budget from parent embed
     * @param array $visited     ['table:uid' => true] cycle-prevention guard
     */
    public function serialize(
        array $row,
        array $config,
        string $baseUrl,
        array $fields = [],
        array $prefetched = [],
        int $remainingDepth = -1,
        array $visited = [],
    ): array {
        $table = $config['general']['table'];
        $record = $this->recordFactory->createResolvedRecordFromDatabaseRow($table, $row);
        $schema = $this->schemaFactory->get($table);

        $result = [
            '@type' => $config['general']['resourceType'],
            '@id'   => $baseUrl . '/' . $record->getUid(),
            'uid'   => $record->getUid(),
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

            if ($field instanceof RelationalFieldTypeInterface) {
                if ($field->getRelationshipType()->hasOne()) {
                    $propertyName = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;
                    $result[$propertyName] = $this->serializeHasOne(
                        $column,
                        $columnConfig,
                        $config,
                        $row,
                        $record,
                        $field,
                        $prefetched,
                        $remainingDepth,
                        $visited,
                    );
                } else {
                    $collection = $record->get($column);
                    $result[$column] = array_map(
                        fn (RecordInterface $item) => $this->buildShallowEmbed($item, $columnConfig),
                        $collection instanceof \Traversable ? iterator_to_array($collection, false) : [],
                    );
                }
            } else {
                $result[$column] = $record->has($column) ? $record->get($column) : null;
            }
        }

        return $result;
    }

    public function serializeCollection(
        array $rows,
        array $config,
        string $baseUrl,
        array $fields = [],
        array $prefetched = [],
    ): array {
        return array_map(
            fn (array $row) => $this->serialize($row, $config, $baseUrl, $fields, $prefetched),
            $rows,
        );
    }

    /**
     * Shallow stub: {@ id, @type, uid} — for relations without embed config.
     * Superseded by 'embed' config for full record embedding (replaces deprecated inlineFields pattern).
     */
    private function buildShallowEmbed(RecordInterface $record, array $columnConfig): array
    {
        $relatedConfig = ApiRegistry::getByTable($record->getMainType());

        return [
            '@id'   => '/_api/' . ($columnConfig['resourceName'] ?? $relatedConfig['general']['resourceName'] ?? $record->getMainType()) . '/' . $record->getUid(),
            '@type' => $columnConfig['resourceType'] ?? $relatedConfig['general']['resourceType'] ?? $record->getMainType(),
            'uid'   => $record->getUid(),
        ];
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
        RecordInterface $record,
        RelationalFieldTypeInterface $fieldObj,
        array $prefetched,
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

        if ($effectiveDepth <= 0) {
            $resourceName = $columnConfig['resourceName'] ?? ($relatedConfig !== null ? $relatedConfig['general']['resourceName'] : $foreignTable);
            $resourceType = $columnConfig['resourceType'] ?? ($relatedConfig !== null ? $relatedConfig['general']['resourceType'] : $foreignTable);
            return ['@id' => '/_api/' . $resourceName . '/' . $fkValue, '@type' => $resourceType, 'uid' => $fkValue];
        }

        $visitKey = $foreignTable . ':' . $fkValue;

        if (isset($visited[$visitKey]) || $relatedConfig === null) {
            // Cycle detected or unregistered table: return stub
            if ($relatedConfig !== null) {
                return [
                    '@id'   => '/_api/' . $relatedConfig['general']['resourceName'] . '/' . $fkValue,
                    '@type' => $relatedConfig['general']['resourceType'],
                    'uid'   => $fkValue,
                ];
            }

            $related = $record->get($column);
            return ($related instanceof RecordInterface) ? $this->buildShallowEmbed($related, $columnConfig) : null;
        }

        // Get or fetch the related row
        $relatedRow = $prefetched[$foreignTable][$fkValue]
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
            $prefetched,
            $effectiveDepth - 1,
            $newVisited,
        );
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
