<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\DataAccess\FileUploadService;
use MaikSchneider\TcaApi\DataAccess\RelationInputResolver;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use MaikSchneider\TcaApi\Event\AfterWriteEvent;
use MaikSchneider\TcaApi\Event\BeforeWriteEvent;
use MaikSchneider\TcaApi\Security\WriteContextFactory;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use MaikSchneider\TcaApi\Validation\FieldValidator;
use MaikSchneider\TcaApi\Validation\UploadValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
class UpdateHandler implements OperationHandlerInterface
{
    use ColumnFilterTrait;
    use FileUploadTrait;
    use WriteOperationTrait;

    public function __construct(
        private readonly DataWriteService $writeService,
        private readonly DataRepository $dataRepository,
        private readonly ResourceSerializer $serializer,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly FieldValidator $fieldValidator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RelationInputResolver $relationResolver,
        private readonly WriteContextFactory $writeContextFactory,
        private readonly FileUploadService $fileUploadService,
        private readonly UploadValidator $uploadValidator,
    ) {
    }

    public function supports(ServerRequestInterface $request, string $operation, ApiDefinition $config): bool
    {
        return $operation === 'update';
    }

    public function handle(ServerRequestInterface $request, ApiDefinition $config): ResponseInterface
    {
        $uid     = (int)$request->getAttribute('tca_api.uid');
        $partial = (bool)$request->getAttribute('tca_api.partial', false);

        // Use the record already fetched by RequestDispatcher (avoids redundant DB query).
        $existingRecord = $request->getAttribute('tca_api.existing_record');
        if ($existingRecord === null || $existingRecord === []) {
            return $this->hydraResponseBuilder->buildError(404, 'Resource not found.', 'Not Found');
        }

        $parsed = $this->parseBody($request, $config);
        if ($parsed instanceof ResponseInterface) {
            return $parsed;
        }

        $result = $this->validateAndResolve($parsed['body'], $parsed['uploadedFiles'], $config, $request, $partial);
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        $data        = $result['data'];
        $resolved    = $result['resolved'];
        $storedFiles = $result['storedFiles'];

        $beforeEvent = new BeforeWriteEvent($config->table, 'update', $data);
        $this->eventDispatcher->dispatch($beforeEvent);
        $data = $beforeEvent->getData();

        // Call 1: update parent record and any new related records (no file columns).
        $dataMap      = [$config->table => [$uid => $data]] + $resolved->extraDataMap;
        $writeContext = $this->writeContextFactory->fromRequest($request, $config->writeMode);
        $this->writeService->processDataMap($dataMap, $writeContext);

        // Call 2: replace existing file references, then attach the new ones.
        // Deleting first prevents stale references from accumulating (maxitems=1).
        if ($storedFiles !== []) {
            $this->deleteExistingFileReferences($storedFiles, $config, $uid, $writeContext);
            $this->attachFileReferences($storedFiles, $config, $uid, $writeContext);
        }

        $this->eventDispatcher->dispatch(new AfterWriteEvent($config->table, 'update', $uid));

        $row       = $this->dataRepository->findById($config->table, $uid, $config);
        $apiPrefix = (string)$request->getAttribute('tca_api.api_prefix', '/_api');
        $baseUrl   = $apiPrefix . '/' . $config->resourceName;

        $serialized = $this->serializer->serialize($row, $config, $baseUrl);

        $event = new AfterOperationEvent('update', $serialized);
        $this->eventDispatcher->dispatch($event);

        return $this->hydraResponseBuilder->buildItem($event->getData());
    }

    public function getPriority(): int
    {
        return 10;
    }
}
