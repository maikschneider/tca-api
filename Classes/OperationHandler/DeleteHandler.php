<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\Event\AfterWriteEvent;
use MaikSchneider\TcaApi\Event\BeforeWriteEvent;
use MaikSchneider\TcaApi\Security\WriteContextFactory;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
class DeleteHandler implements OperationHandlerInterface
{
    public function __construct(
        private readonly DataWriteService $writeService,
        private readonly DataRepository $dataRepository,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly WriteContextFactory $writeContextFactory,
    ) {
    }

    public function supports(ServerRequestInterface $request, string $operation, ApiDefinition $config): bool
    {
        return $operation === 'delete';
    }

    public function handle(ServerRequestInterface $request, ApiDefinition $config): ResponseInterface
    {
        $uid = (int)$request->getAttribute('tca_api.uid');

        return $this->doHandle($request, $config, $uid);
    }

    public function getPriority(): int
    {
        return 10;
    }

    private function doHandle(ServerRequestInterface $request, ApiDefinition $config, int $uid): ResponseInterface
    {
        if ($this->dataRepository->findById($config->table, $uid, $config) === null) {
            return $this->hydraResponseBuilder->buildError(404, 'Resource not found.', 'Not Found');
        }

        $writeContext = $this->writeContextFactory->fromRequest($request, $config->writeMode);
        $this->eventDispatcher->dispatch(new BeforeWriteEvent($config->table, 'delete', []));
        $this->writeService->delete($config->table, $uid, $writeContext);
        $this->eventDispatcher->dispatch(new AfterWriteEvent($config->table, 'delete', $uid));

        return $this->responseFactory->createResponse(204);
    }
}
