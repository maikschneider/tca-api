<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Filter\FilterContext;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Request-free orchestration of the read pipeline: validate filters/order,
 * resolve pagination, fetch via {@see DataRepository}, eliminate N+1 via
 * {@see EmbedPreloader}, and serialize via {@see ResourceSerializer}.
 *
 * This is the single code path shared by the HTTP operation handlers
 * (GetCollectionHandler / GetItemHandler) and any non-HTTP consumer such as the
 * Fluid data layer. The handlers translate request attributes into a
 * {@see CollectionQuery}/{@see ItemQuery}; everything below this point is
 * identical regardless of how the parameters were obtained.
 *
 * The provider operates on an already-resolved {@see ApiDefinition} — resolving
 * a resource name to its config (via the registry) is the caller's job, so the
 * provider never depends on the registry key matching the config's resourceName.
 */
#[Autoconfigure(public: true)]
final class ResourceDataProvider
{
    private const DEFAULT_ITEMS_PER_PAGE = 20;

    public function __construct(
        private readonly DataRepository $dataRepository,
        private readonly EmbedPreloader $embedPreloader,
        private readonly ResourceSerializer $serializer,
    ) {
    }

    public function getCollection(ApiDefinition $config, CollectionQuery $query): CollectionResult
    {
        $page         = max(1, $query->page);
        $itemsPerPage = $this->resolveItemsPerPage($query->itemsPerPage, $config);
        $offset       = ($page - 1) * $itemsPerPage;

        $constraints = $this->resolveFilters($query->filters, $config, $query->request);
        $order       = $this->resolveOrder($query->order, $config);
        $baseUrl     = $this->baseUrl($query->baseUrl, $config);

        $total     = $this->dataRepository->count($config->table, $constraints, $config, $query->language);
        $rows      = $this->dataRepository->findCollection($config->table, $constraints, $itemsPerPage, $offset, $order, $config, $query->language);
        $preloaded = $this->embedPreloader->preload($rows, $config, $query->language);
        $members   = array_values($this->serializer->serializeCollection($rows, $config, $baseUrl, $query->fields, $preloaded, $query->operation));

        return new CollectionResult($members, $total, $page, $itemsPerPage);
    }

    /**
     * @return array<string, mixed>|null the serialized record, or null when not found
     */
    public function getItem(ApiDefinition $config, int $uid, ItemQuery $query): ?array
    {
        $row = $this->dataRepository->findById($config->table, $uid, $config, $query->language);
        if ($row === null) {
            return null;
        }

        $preloaded = $this->embedPreloader->preload([$row], $config, $query->language);

        return $this->serializer->serialize($row, $config, $this->baseUrl($query->baseUrl, $config), $query->fields, $preloaded, -1, [], $query->operation);
    }

    /**
     * Resolve the page size: requested value > config default > hard fallback,
     * floored at 1 and capped by the config's maxItemsPerPage when set.
     */
    private function resolveItemsPerPage(?int $requested, ApiDefinition $config): int
    {
        $itemsPerPage = max(1, $requested ?? $config->itemsPerPage ?? self::DEFAULT_ITEMS_PER_PAGE);
        if ($config->maxItemsPerPage !== null) {
            $itemsPerPage = min($itemsPerPage, $config->maxItemsPerPage);
        }

        return $itemsPerPage;
    }

    /**
     * Build the [filterClass, FilterContext] constraint tuples consumed by the
     * repository. Private filters always use their server-side default; public
     * filters take the requested value, falling back to a configured default.
     *
     * @param array<string, mixed> $requested
     * @return array<string, array{0: string, 1: FilterContext}>
     */
    private function resolveFilters(array $requested, ApiDefinition $config, ?ServerRequestInterface $request): array
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

    /**
     * Validate the requested order against the config allowlist, falling back to
     * the configured default order when nothing valid was requested.
     *
     * @param array<string, string> $requested
     * @return array<string, string>
     */
    private function resolveOrder(array $requested, ApiDefinition $config): array
    {
        if ($requested === []) {
            return $config->defaultOrder;
        }

        $safe = [];
        foreach ($requested as $column => $direction) {
            if (\in_array($column, $config->allowedOrder, true)) {
                $safe[$column] = \strtolower((string)$direction) === 'desc' ? 'desc' : 'asc';
            }
        }

        return $safe ?: $config->defaultOrder;
    }

    private function baseUrl(string $baseUrl, ApiDefinition $config): string
    {
        return $baseUrl !== '' ? $baseUrl : '/_api/' . $config->resourceName;
    }
}
