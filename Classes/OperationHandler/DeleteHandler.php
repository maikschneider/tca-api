<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\Event\AfterWriteEvent;
use MaikSchneider\TcaApi\Event\BeforeWriteEvent;
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
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function supports(ServerRequestInterface $request, string $operation, array $config): bool
    {
        return $operation === 'delete';
    }

    public function handle(ServerRequestInterface $request, array $config): ResponseInterface
    {
        $uid = (int)$request->getAttribute('tca_api.uid');

        return $this->doHandle($config, $uid);
    }

    public function getPriority(): int
    {
        return 10;
    }

    private function doHandle(array $config, int $uid): ResponseInterface
    {
        $table = $config['general']['table'];

        if ($this->dataRepository->findById($table, $uid, $config) === null) {
            return $this->responseFactory->createResponse(404)
                ->withHeader('Content-Type', 'application/ld+json');
        }

        $this->eventDispatcher->dispatch(new BeforeWriteEvent($table, 'delete', []));
        $this->writeService->delete($table, $uid);
        $this->eventDispatcher->dispatch(new AfterWriteEvent($table, 'delete', $uid));

        return $this->responseFactory->createResponse(204);
    }
}
