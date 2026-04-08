<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class GetItemHandler
{
    public function __construct(
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function supports(string $httpMethod, string $operation): bool
    {
        return $httpMethod === 'GET' && $operation === 'show';
    }

    public function handle(ServerRequestInterface $request, array $config, int $uid): ResponseInterface
    {
        $table = $config['general']['table'];
        $baseUrl = '/_api/' . $config['general']['resourceName'];

        $row = $this->dataRepository->findById($table, $uid, $config);
        if ($row === null) {
            return $this->responseFactory->createResponse(404)
                ->withHeader('Content-Type', 'application/ld+json');
        }

        $serialized = $this->serializer->serialize($row, $config, $baseUrl);

        $event = new AfterOperationEvent('show', $serialized);
        $this->eventDispatcher->dispatch($event);

        return $this->hydraResponseBuilder->buildItem($event->getData());
    }

    public function getPriority(): int
    {
        return 10;
    }
}
