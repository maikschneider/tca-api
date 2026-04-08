<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

class GetCollectionHandler
{
    public function __construct(
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly TcaSchemaFactory $schemaFactory,
    ) {
    }

    public function supports(string $httpMethod, string $operation): bool
    {
        return $httpMethod === 'GET' && $operation === 'list';
    }

    public function handle(
        ServerRequestInterface $request,
        array $config,
        int $page,
        int $itemsPerPage,
        array $filters = [],
        array $order = [],
        array $fields = [],
    ): ResponseInterface {
        $table = $config['general']['table'];
        $baseUrl = '/_api/' . $config['general']['resourceName'];
        $offset = ($page - 1) * $itemsPerPage;

        $safeFilters = $this->resolveFilters($filters, $config);
        $safeOrder = $this->resolveOrder($order, $config);

        $total = $this->dataRepository->count($table, $safeFilters, $config);
        $rows = $this->dataRepository->findCollection($table, $safeFilters, $itemsPerPage, $offset, $safeOrder, $config);
        $prefetched = $this->prefetchEmbeds($rows, $config);
        $members = $this->serializer->serializeCollection($rows, $config, $baseUrl, $fields, $prefetched);

        $event = new AfterOperationEvent('list', $members);
        $this->eventDispatcher->dispatch($event);

        return $this->hydraResponseBuilder->buildCollection($event->getData(), $total, $baseUrl, $page, $itemsPerPage);
    }

    public function getPriority(): int
    {
        return 10;
    }

    /**
     * Bulk-fetch related records for all embed-configured hasOne columns.
     * Returns [foreignTable => [uid => row]] keyed map for N+1-free serialization.
     */
    private function prefetchEmbeds(array $rows, array $config): array
    {
        $table = $config['general']['table'];
        $schema = $this->schemaFactory->get($table);
        $byTable = [];

        foreach ($config['columns'] as $column => $columnConfig) {
            $embed = $columnConfig['embed'] ?? null;
            if ($embed === null || $embed === false) {
                continue;
            }

            if (!$schema->hasField($column)) {
                continue;
            }

            $field = $schema->getField($column);
            if (!($field instanceof RelationalFieldTypeInterface) || !$field->getRelationshipType()->hasOne()) {
                continue;
            }

            $foreignTable = $field->getConfiguration()['foreign_table'] ?? null;
            if ($foreignTable === null) {
                continue;
            }

            foreach ($rows as $row) {
                $fk = (int)($row[$column] ?? 0);
                if ($fk > 0) {
                    $byTable[$foreignTable][$fk] = true;
                }
            }
        }

        $prefetched = [];
        foreach ($byTable as $foreignTable => $fkSet) {
            $prefetched[$foreignTable] = $this->dataRepository->findByIds($foreignTable, array_keys($fkSet));
        }

        return $prefetched;
    }

    private function resolveFilters(array $requested, array $config): array
    {
        $declared = $config['filters'] ?? [];
        $safe = [];
        foreach ($requested as $column => $value) {
            if (isset($declared[$column])) {
                $safe[$column] = array_merge($declared[$column], [
                    'value'   => $value,
                    '_table'  => $config['general']['table'],
                    '_column' => $column,
                ]);
            }
        }
        return $safe;
    }

    private function resolveOrder(array $requested, array $config): array
    {
        $allowed = $config['order']['allowed'] ?? [];
        $default = $config['order']['default'] ?? [];

        if (empty($requested)) {
            return $default;
        }

        $safe = [];
        foreach ($requested as $column => $direction) {
            if (\in_array($column, $allowed, true)) {
                $safe[$column] = \strtolower($direction) === 'desc' ? 'desc' : 'asc';
            }
        }

        return $safe ?: $default;
    }
}
