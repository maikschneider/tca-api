<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\CollectionQuery;
use MaikSchneider\TcaApi\DataAccess\ResourceDataProvider;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
class GetCollectionHandler implements OperationHandlerInterface
{
    use LanguageAwareTrait;

    public function __construct(
        private readonly ResourceDataProvider $dataProvider,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly EventDispatcherInterface $eventDispatcher,
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

        $result = $this->dataProvider->getCollection($config, new CollectionQuery(
            page:         $page,
            itemsPerPage: $itemsPerPage,
            filters:      $filters,
            order:        $order,
            fields:       array_filter($fields, 'is_string'),
            language:     $this->languageFromRequest($request),
            operation:    'list',
            request:      $request,
            baseUrl:      $baseUrl,
        ));

        $event = new AfterOperationEvent('list', $result->members);
        $this->eventDispatcher->dispatch($event);

        $queryState = array_diff_key($request->getQueryParams(), ['page' => null]);
        $queryState['itemsPerPage'] = $result->itemsPerPage;

        return $this->hydraResponseBuilder->buildCollection(
            $event->getData(),
            $result->total,
            $baseUrl,
            $result->page,
            $result->itemsPerPage,
            $queryState,
            $config,
        );
    }
}
