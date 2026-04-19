<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\EmbedPreloader;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
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

    public function supports(ServerRequestInterface $request, string $operation, array $config): bool
    {
        return $operation === 'list';
    }

    public function handle(ServerRequestInterface $request, array $config): ResponseInterface
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
        array $config,
        int $page,
        int $itemsPerPage,
        array $filters = [],
        array $order = [],
        array $fields = [],
    ): ResponseInterface {
        $table   = $config['general']['table'];
        $baseUrl = '/_api/' . $config['general']['resourceName'];
        $offset  = ($page - 1) * $itemsPerPage;

        $safeFilters = $this->resolveFilters($filters, $config, $request);
        $safeOrder   = $this->resolveOrder($order, $config);

        $total     = $this->dataRepository->count($table, $safeFilters, $config);
        $rows      = $this->dataRepository->findCollection($table, $safeFilters, $itemsPerPage, $offset, $safeOrder, $config);
        $preloaded = $this->embedPreloader->preload($rows, $config);
        $members   = $this->serializer->serializeCollection($rows, $config, $baseUrl, $fields, $preloaded, 'list');

        $event = new AfterOperationEvent('list', $members);
        $this->eventDispatcher->dispatch($event);

        return $this->hydraResponseBuilder->buildCollection($event->getData(), $total, $baseUrl, $page, $itemsPerPage);
    }

    private function resolveFilters(array $requested, array $config, ServerRequestInterface $request): array
    {
        $declared = $config['filters'] ?? [];
        $safe     = [];

        foreach ($declared as $column => $filterDef) {
            [$class, $options] = $this->normalizeFilterDef($column, $filterDef);
            $isPrivate = (bool)($options['private'] ?? false);
            $default   = $options['default'] ?? null;
            $cleanOpts = array_diff_key($options, array_flip(['default', 'private']));

            if ($isPrivate) {
                $value = $default;
            } elseif (isset($requested[$column])) {
                $value = $requested[$column];
            } elseif ($default !== null) {
                $value = $default;
            } else {
                continue;
            }

            $safe[$column] = array_merge($cleanOpts, [
                'value'           => $value,
                '_table'          => $config['general']['table'],
                '_column'         => $column,
                '_filterClass'    => $class,
                '_request'        => $request,
                '_resourceConfig' => $config,
            ]);
        }

        return $safe;
    }

    private function normalizeFilterDef(string $column, mixed $filterDef): array
    {
        if (is_string($filterDef)) {
            return [$filterDef, []];
        }
        if (is_array($filterDef) && is_string($filterDef[0] ?? null)) {
            return [$filterDef[0], $filterDef[1] ?? []];
        }
        throw new \InvalidArgumentException(
            sprintf('Invalid filter definition for column "%s": expected a class name or [ClassName, options].', $column),
        );
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
