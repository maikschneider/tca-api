<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

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
 * Note: RecordFactory and the Schema API are marked @internal in TYPO3 core.
 * They are used here intentionally as the canonical v13 record/schema layer.
 */
class ResourceSerializer
{
    public function __construct(
        private readonly RecordFactory $recordFactory,
        private readonly TcaSchemaFactory $schemaFactory,
    ) {
    }

    public function serialize(array $row, array $config, string $baseUrl): array
    {
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

            if (!$schema->hasField($column)) {
                continue;
            }

            $field = $schema->getField($column);

            if ($field instanceof RelationalFieldTypeInterface) {
                $relationshipType = $field->getRelationshipType();

                if ($relationshipType->hasOne()) {
                    $propertyName = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;
                    $related = $record->get($column);
                    $result[$propertyName] = ($related instanceof RecordInterface)
                        ? $this->buildShallowEmbed($related, $columnConfig)
                        : null;
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

    public function serializeCollection(array $rows, array $config, string $baseUrl): array
    {
        return array_map(fn (array $row) => $this->serialize($row, $config, $baseUrl), $rows);
    }

    private function buildShallowEmbed(RecordInterface $record, array $columnConfig): array
    {
        $relatedConfig = ApiRegistry::getByTable($record->getMainType());

        return [
            '@id'   => '/_api/' . ($columnConfig['resourceName'] ?? $relatedConfig['general']['resourceName'] ?? $record->getMainType()) . '/' . $record->getUid(),
            '@type' => $columnConfig['resourceType'] ?? $relatedConfig['general']['resourceType'] ?? $record->getMainType(),
            'uid'   => $record->getUid(),
        ];
    }
}
