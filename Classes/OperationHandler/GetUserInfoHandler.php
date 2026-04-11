<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\EmbedPreloader;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles GET /_api/{userinfo-resource} — returns the authenticated FE user as a JSON-LD item.
 *
 * The UID is read from the authenticated frontend user in the request, not from the URL.
 * Access control is enforced by the dispatcher before this handler is called.
 */
class GetUserInfoHandler
{
    public function __construct(
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EmbedPreloader $embedPreloader,
    ) {
    }

    public function handle(ServerRequestInterface $request, array $config, array $fields = []): ResponseInterface
    {
        $feUser = $request->getAttribute('frontend.user');
        $uid    = (int)($feUser->user['uid'] ?? 0);

        $table   = $config['general']['table'];
        $baseUrl = '/_api/' . $config['general']['resourceName'];

        $row = $this->dataRepository->findById($table, $uid, $config);
        if ($row === null) {
            return $this->responseFactory->createResponse(404)
                ->withHeader('Content-Type', 'application/ld+json');
        }

        $preloaded  = $this->embedPreloader->preload([$row], $config);
        $serialized = $this->serializer->serialize($row, $config, $baseUrl, $fields, $preloaded);

        $event = new AfterOperationEvent('userinfo', $serialized);
        $this->eventDispatcher->dispatch($event);

        return $this->hydraResponseBuilder->buildItem($event->getData());
    }
}
