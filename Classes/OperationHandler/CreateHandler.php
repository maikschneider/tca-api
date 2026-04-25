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
class CreateHandler implements OperationHandlerInterface
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
        return $operation === 'create';
    }

    public function handle(ServerRequestInterface $request, ApiDefinition $config): ResponseInterface
    {
        $parsed = $this->parseBody($request, $config);
        if ($parsed instanceof ResponseInterface) {
            return $parsed;
        }

        $result = $this->validateAndResolve($parsed['body'], $parsed['uploadedFiles'], $config, $request);
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        $data     = $result['data'];
        $resolved = $result['resolved'];
        $storedFiles = $result['storedFiles'];

        $feUser = $request->getAttribute('frontend.user');

        $data['pid'] = $config->storagePid ?? 0;

        if ($feUser !== null && !empty($feUser->user['uid'])) {
            $feUid = (int)$feUser->user['uid'];
            if ($config->ownershipColumn !== null) {
                $data[$config->ownershipColumn] = $feUid;
            }
            if ($config->ownershipSetOnCreate !== null && $config->ownershipSetOnCreate !== $config->ownershipColumn) {
                $data[$config->ownershipSetOnCreate] = $feUid;
            }
        }

        // Store uploaded files in FAL now (after validation, before DataHandler).
        // Returns a map of column -> [refKey => refData] for the second DataHandler call.

        $beforeEvent = new BeforeWriteEvent($config->table, 'create', $data);
        $this->eventDispatcher->dispatch($beforeEvent);
        $data = $beforeEvent->getData();

        // Call 1: create the parent record and all related records.
        // File references are NOT included here because uid_foreign cannot be known
        // before the parent record exists. DataHandler's NEW_xxx remapping does not
        // propagate uid_foreign to sys_file_reference for type=file columns.
        $primaryKey   = 'NEW_primary';
        $dataMap      = [$config->table => [$primaryKey => $data]] + $resolved->extraDataMap;
        $writeContext = $this->writeContextFactory->fromRequest($request, $config->writeMode);
        $substMap     = $this->writeService->processDataMap($dataMap, $writeContext);
        $uid          = (int)($substMap[$primaryKey] ?? 0);

        // Call 2: attach file references now that the parent UID is known.
        // The parent record is updated with the reference placeholders so DataHandler
        // sets uid_foreign correctly and updates the column count.
        if ($storedFiles !== [] && $uid > 0) {
            $this->attachFileReferences($storedFiles, $config, $uid, $writeContext);
        }

        $this->eventDispatcher->dispatch(new AfterWriteEvent($config->table, 'create', $uid));

        $row       = $this->dataRepository->findById($config->table, $uid, $config);
        $apiPrefix = (string)$request->getAttribute('tca_api.api_prefix', '/_api');
        $baseUrl   = $apiPrefix . '/' . $config->resourceName;
        $location  = $baseUrl . '/' . $uid;

        $serialized = $this->serializer->serialize($row, $config, $baseUrl);

        $event = new AfterOperationEvent('create', $serialized);
        $this->eventDispatcher->dispatch($event);

        return $this->hydraResponseBuilder->buildItem($event->getData())
            ->withStatus(201)
            ->withHeader('Location', $location);
    }

    public function getPriority(): int
    {
        return 10;
    }
}
