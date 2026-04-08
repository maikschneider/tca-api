<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\Registry\ApiRegistry;
use TYPO3\CMS\Core\Collection\LazyRecordCollection;
use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\CMS\Core\Domain\RecordInterface;

/**
 * Serializes a TCA domain record to a Hydra JSON-LD array.
 *
 * Relation resolution is delegated to RecordFactory::createResolvedRecordFromDatabaseRow(),
 * which uses TYPO3's RecordFieldTransformer to lazily resolve any TCA-defined relation
 * (select+foreign_table, select+MM, category, inline) into typed Record objects.
 * This eliminates duplicating relation metadata (mmTable, mmLocalKey, etc.) in the API config.
 *
 * Note: RecordFactory and related classes are marked @internal in TYPO3 core.
 * They are used here intentionally as they are the canonical way to work with records in v13.
 */
class ResourceSerializer
{
    public function __construct(
        private readonly RecordFactory $recordFactory,
    ) {}

    public function serialize(array $row, array $config, string $baseUrl): array
    {
        $table = $config['general']['table'];
        $record = $this->recordFactory->createResolvedRecordFromDatabaseRow($table, $row);

        $result = [
            '@type' => $config['general']['resourceType'],
            '@id'   => $baseUrl . '/' . $record->getUid(),
            'uid'   => $record->getUid(),
        ];

        foreach ($config['columns'] as $column => $columnConfig) {
            if (!($columnConfig['readable'] ?? false)) {
                continue;
            }

            $tcaConfig = $GLOBALS['TCA'][$table]['columns'][$column]['config'] ?? [];
            $tcaType = $tcaConfig['type'] ?? '';

            if ($this->isToOneRelation($tcaType, $tcaConfig)) {
                $propertyName = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;
                $related = $record->get($column);
                $result[$propertyName] = ($related instanceof RecordInterface)
                    ? $this->buildShallowEmbed($related, $columnConfig)
                    : null;
            } elseif ($this->isToManyRelation($tcaType, $tcaConfig)) {
                $collection = $record->get($column);
                $result[$column] = array_map(
                    fn(RecordInterface $item) => $this->buildShallowEmbed($item, $columnConfig),
                    $collection instanceof \Traversable ? iterator_to_array($collection, false) : [],
                );
            } else {
                $result[$column] = $record->has($column) ? $record->get($column) : null;
            }
        }

        return $result;
    }

    public function serializeCollection(array $rows, array $config, string $baseUrl): array
    {
        return array_map(fn(array $row) => $this->serialize($row, $config, $baseUrl), $rows);
    }

    private function isToOneRelation(string $tcaType, array $tcaConfig): bool
    {
        return $tcaType === 'select'
            && isset($tcaConfig['foreign_table'])
            && !isset($tcaConfig['MM']);
    }

    private function isToManyRelation(string $tcaType, array $tcaConfig): bool
    {
        return $tcaType === 'category'
            || $tcaType === 'inline'
            || ($tcaType === 'select' && isset($tcaConfig['foreign_table'], $tcaConfig['MM']));
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
