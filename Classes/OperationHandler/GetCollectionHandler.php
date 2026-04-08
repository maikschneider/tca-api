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

class GetCollectionHandler
{
    public function __construct(
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly EventDispatcherInterface $eventDispatcher,
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
    ): ResponseInterface {
        $table = $config['general']['table'];
        $baseUrl = '/_api/' . $config['general']['resourceName'];
        $offset = ($page - 1) * $itemsPerPage;

        $safeFilters = $this->resolveFilters($filters, $config);
        $safeOrder = $this->resolveOrder($order, $config);

        $total = $this->dataRepository->count($table, $safeFilters, $config);
        $rows = $this->dataRepository->findCollection($table, $safeFilters, $itemsPerPage, $offset, $safeOrder, $config);
        $members = $this->serializer->serializeCollection($rows, $config, $baseUrl);

        $event = new AfterOperationEvent('list', $members);
        $this->eventDispatcher->dispatch($event);

        return $this->hydraResponseBuilder->buildCollection($event->getData(), $total, $baseUrl, $page, $itemsPerPage);
    }

    public function getPriority(): int
    {
        return 10;
    }

    private function resolveFilters(array $requested, array $config): array
    {
        $declared = $config['filters'] ?? [];
        $safe = [];
        foreach ($requested as $column => $value) {
            if (isset($declared[$column])) {
                $safe[$column] = array_merge($declared[$column], ['value' => $value]);
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
