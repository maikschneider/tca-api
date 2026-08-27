<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\ItemQuery;
use MaikSchneider\TcaApi\DataAccess\ResourceDataProvider;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
class GetItemHandler implements OperationHandlerInterface
{
    use LanguageAwareTrait;

    public function __construct(
        private readonly ResourceDataProvider $dataProvider,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function supports(ServerRequestInterface $request, string $operation, ApiDefinition $config): bool
    {
        return $operation === 'show';
    }

    public function handle(ServerRequestInterface $request, ApiDefinition $config): ResponseInterface
    {
        $uid    = (int)$request->getAttribute('tca_api.uid');
        $fields = (array)$request->getAttribute('tca_api.fields', []);

        return $this->doHandle($request, $config, $uid, $fields);
    }

    public function getPriority(): int
    {
        return 10;
    }

    private function doHandle(ServerRequestInterface $request, ApiDefinition $config, int $uid, array $fields): ResponseInterface
    {
        $apiPrefix = (string)$request->getAttribute('tca_api.api_prefix', '/_api');
        $baseUrl   = $apiPrefix . '/' . $config->resourceName;

        $serialized = $this->dataProvider->getItem($config, $uid, new ItemQuery(
            fields:    array_filter($fields, 'is_string'),
            language:  $this->languageFromRequest($request),
            operation: 'show',
            request:   $request,
            baseUrl:   $baseUrl,
        ));

        if ($serialized === null) {
            return $this->responseFactory->createResponse(404)
                ->withHeader('Content-Type', 'application/ld+json');
        }

        $event = new AfterOperationEvent('show', $serialized);
        $this->eventDispatcher->dispatch($event);

        return $this->hydraResponseBuilder->buildItem($event->getData());
    }
}
