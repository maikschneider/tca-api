<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\EmbedPreloader;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use MaikSchneider\TcaApi\Filter\FilterContext;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
class GetCollectionHandler implements OperationHandlerInterface
{
    public function __construct(
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EmbedPreloader $embedPreloader,
    ) {
    }

    public function supports(ServerRequestInterface $request, string $operation, ApiDefinition $config): bool
    {
        return $operation === 'list';
    }

    public function handle(ServerRequestInterface $request, ApiDefinition $config): ResponseInterface
    {
        $page         = (int)$request->getAttribute('tca_api.page', 1);
        $itemsPerPage = (int)$request->getAttribute('tca_api.items_per_page', 20);
        $filters      = (array)$request->getAttribute('tca_api.filters', []);
        $order        = (array)$request->getAttribute('tca_api.order', []);
        $fields       = (array)$request->getAttribute('tca_api.fields', []);

        return $this->doHandle($request, $config, $page, $itemsPerPage, $filters, $order, $fields);
    }

    public function getPriority(): int
    {
        return 10;
    }

    private function doHandle(
        ServerRequestInterface $request,
        ApiDefinition $config,
        int $page,
        int $itemsPerPage,
        array $filters = [],
        array $order = [],
        array $fields = [],
    ): ResponseInterface {
        $apiPrefix = (string)$request->getAttribute('tca_api.api_prefix', '/_api');
        $baseUrl   = $apiPrefix . '/' . $config->resourceName;
        $offset    = ($page - 1) * $itemsPerPage;

        $safeFilters = $this->resolveFilters($filters, $config, $request);
        $safeOrder   = $this->resolveOrder($order, $config);

        $total     = $this->dataRepository->count($config->table, $safeFilters, $config);
        $rows      = $this->dataRepository->findCollection($config->table, $safeFilters, $itemsPerPage, $offset, $safeOrder, $config);
        $preloaded = $this->embedPreloader->preload($rows, $config);
        $members   = $this->serializer->serializeCollection($rows, $config, $baseUrl, $fields, $preloaded, 'list');

        $event = new AfterOperationEvent('list', $members);
        $this->eventDispatcher->dispatch($event);

        $queryState = array_diff_key($request->getQueryParams(), ['page' => null]);
        $queryState['itemsPerPage'] = $itemsPerPage;

        return $this->hydraResponseBuilder->buildCollection($event->getData(), $total, $baseUrl, $page, $itemsPerPage, $queryState);
    }

    private function resolveFilters(array $requested, ApiDefinition $config, ServerRequestInterface $request): array
    {
        $safe = [];

        foreach ($config->filters as $column => $filterDef) {
            if ($filterDef->isPrivate) {
                $value = $filterDef->default;
            } elseif (isset($requested[$column])) {
                $value = $requested[$column];
            } elseif ($filterDef->default !== null) {
                $value = $filterDef->default;
            } else {
                continue;
            }

            $safe[$column] = [$filterDef->filterClass, new FilterContext(
                value:          $value,
                table:          $config->table,
                column:         $column,
                options:        $filterDef->options,
                request:        $request,
                resourceConfig: $config,
            )];
        }

        return $safe;
    }

    private function resolveOrder(array $requested, ApiDefinition $config): array
    {
        if (empty($requested)) {
            return $config->defaultOrder;
        }

        $safe = [];
        foreach ($requested as $column => $direction) {
            if (\in_array($column, $config->allowedOrder, true)) {
                $safe[$column] = \strtolower($direction) === 'desc' ? 'desc' : 'asc';
            }
        }

        return $safe ?: $config->defaultOrder;
    }
}
