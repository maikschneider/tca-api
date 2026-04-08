<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\Event\AfterWriteEvent;
use MaikSchneider\TcaApi\Event\BeforeWriteEvent;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use MaikSchneider\TcaApi\Validation\FieldValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class UpdateHandler
{
    public function __construct(
        private readonly DataWriteService $writeService,
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly FieldValidator $fieldValidator,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function handle(ServerRequestInterface $request, array $config, int $uid, bool $partial = false): ResponseInterface
    {
        $table = $config['general']['table'];

        if ($this->dataRepository->findById($table, $uid, $config) === null) {
            return $this->responseFactory->createResponse(404)
                ->withHeader('Content-Type', 'application/ld+json');
        }

        $raw = (string)$request->getBody();
        $body = $raw !== '' ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? []) : [];

        $violations = $this->fieldValidator->validate($body, $config, $partial);
        if ($violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($violations);
        }

        $data = $this->filterWritableColumns($body, $config);

        $beforeEvent = new BeforeWriteEvent($table, 'update', $data);
        $this->eventDispatcher->dispatch($beforeEvent);
        $data = $beforeEvent->getData();

        $this->writeService->update($table, $uid, $data);
        $this->eventDispatcher->dispatch(new AfterWriteEvent($table, 'update', $uid));

        $row = $this->dataRepository->findById($table, $uid, $config);
        $baseUrl = '/_api/' . $config['general']['resourceName'];

        return $this->hydraResponseBuilder->buildItem(
            $this->serializer->serialize($row, $config, $baseUrl),
        );
    }

    private function filterWritableColumns(array $body, array $config): array
    {
        $result = [];
        foreach ($config['columns'] as $column => $columnConfig) {
            if (($columnConfig['writable'] ?? false) && \array_key_exists($column, $body)) {
                $result[$column] = $body[$column];
            }
        }
        return $result;
    }
}
