<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
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

        // Use the record already fetched by RequestDispatcher (avoids redundant DB query).
        $existingRecord = $request->getAttribute('tca_api.existing_record');
        if ($existingRecord === null || $existingRecord === []) {
            return $this->hydraResponseBuilder->buildError(404, 'Resource not found.', 'Not Found');
        }

        $writeContext = $this->writeContextFactory->fromRequest($request, $config->writeMode);
        $beforeEvent = new BeforeWriteEvent($config->table, 'delete', []);
        $this->eventDispatcher->dispatch($beforeEvent);
        if ($beforeEvent->isPropagationStopped()) {
            return $this->hydraResponseBuilder->buildError(422, 'Operation aborted by event listener.', 'Unprocessable Entity');
        }
        $this->writeService->delete($config->table, $uid, $writeContext);
        $this->eventDispatcher->dispatch(new AfterWriteEvent($config->table, 'delete', $uid));

        $event = new AfterOperationEvent('delete', []);
        $this->eventDispatcher->dispatch($event);

        return $this->responseFactory->createResponse(204);
    }

    public function getPriority(): int
    {
        return 10;
    }
}
