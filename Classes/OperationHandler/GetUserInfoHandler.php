<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\EmbedPreloader;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Handles GET /_api/{userinfo-resource} — returns the authenticated FE user as a JSON-LD item.
 *
 * The UID is read from the authenticated frontend user in the request, not from the URL.
 * Access control is enforced by the dispatcher before this handler is called.
 */
#[Autoconfigure(public: true)]
class GetUserInfoHandler implements OperationHandlerInterface
{
    use LanguageAwareTrait;

    public function __construct(
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EmbedPreloader $embedPreloader,
    ) {
    }

    public function supports(ServerRequestInterface $request, string $operation, ApiDefinition $config): bool
    {
        return $operation === 'userinfo';
    }

    public function handle(ServerRequestInterface $request, ApiDefinition $config): ResponseInterface
    {
        $fields = (array)$request->getAttribute('tca_api.fields', []);

        return $this->doHandle($request, $config, $fields);
    }

    public function getPriority(): int
    {
        return 10;
    }

    private function doHandle(ServerRequestInterface $request, ApiDefinition $config, array $fields): ResponseInterface
    {
        $feUser = $request->getAttribute('frontend.user');
        $uid    = (int)($feUser->user['uid'] ?? 0);

        $apiPrefix = (string)$request->getAttribute('tca_api.api_prefix', '/_api');
        $baseUrl   = $apiPrefix . '/' . $config->resourceName;
        $language  = $this->languageFromRequest($request);

        $row = $this->dataRepository->findById($config->table, $uid, $config, $language);
        if ($row === null) {
            return $this->responseFactory->createResponse(404)
                ->withHeader('Content-Type', 'application/ld+json');
        }

        $preloaded  = $this->embedPreloader->preload([$row], $config, $language);
        $serialized = $this->serializer->serialize($row, $config, $baseUrl, $fields, $preloaded);

        $event = new AfterOperationEvent('userinfo', $serialized);
        $this->eventDispatcher->dispatch($event);

        return $this->hydraResponseBuilder->buildItem($event->getData());
    }
}
