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
    use WriteHandlerTrait;

    public function __construct(
        DataWriteService $writeService,
        DataRepository $dataRepository,
        ResourceSerializer $serializer,
        HydraResponseBuilder $hydraResponseBuilder,
        FieldValidator $fieldValidator,
        EventDispatcherInterface $eventDispatcher,
        RelationInputResolver $relationResolver,
        WriteContextFactory $writeContextFactory,
        FileUploadService $fileUploadService,
        UploadValidator $uploadValidator,
    ) {
        $this->writeService = $writeService;
        $this->dataRepository = $dataRepository;
        $this->serializer = $serializer;
        $this->hydraResponseBuilder = $hydraResponseBuilder;
        $this->fieldValidator = $fieldValidator;
        $this->eventDispatcher = $eventDispatcher;
        $this->relationResolver = $relationResolver;
        $this->writeContextFactory = $writeContextFactory;
        $this->fileUploadService = $fileUploadService;
        $this->uploadValidator = $uploadValidator;
    }

    public function supports(ServerRequestInterface $request, string $operation, ApiDefinition $config): bool
    {
        return $operation === 'update';
    }

    public function handle(ServerRequestInterface $request, ApiDefinition $config): ResponseInterface
    {
        $uid = (int)$request->getAttribute('tca_api.uid');
        $partial = (bool)$request->getAttribute('tca_api.partial', false);

        return $this->doHandle($request, $config, $uid, $partial);
    }

    public function getPriority(): int
    {
        return 10;
    }

    private function doHandle(ServerRequestInterface $request, ApiDefinition $config, int $uid, bool $partial = false): ResponseInterface
    {
        // Record existence was already verified by RequestDispatcher::resolveExistingRecord()
        // and passed via request attribute. If not available, fall back to query.
        $existingRecord = $request->getAttribute('tca_api.existing_record');
        if ($existingRecord === null) {
            $existingRecord = $this->dataRepository->findById($config->table, $uid, $config);
            if ($existingRecord === null) {
                return $this->hydraResponseBuilder->buildError(404, 'Resource not found.', 'Not Found');
            }
        }

        $parsed = $this->parseRequestBody($request);
        if (isset($parsed['error'])) {
            return $parsed['error'];
        }

        $body = $parsed['body'];
        $uploadedFiles = $parsed['uploadedFiles'];

        $violations = $this->validateUploadedFiles($uploadedFiles, $config);
        if ($violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($violations);
        }

        $violations = $this->fieldValidator->validate($body, $config, $partial);
        if ($violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($violations);
        }

        $resolved = $this->relationResolver->resolve($body, $config, $config->storagePid ?? 0, $request);
        if ($resolved->violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($resolved->violations);
        }

        $data = $this->filterWritableColumns($resolved->scalarBody, $config);

        $storedFiles = $uploadedFiles !== [] ? $this->storeUploadedFiles($uploadedFiles, $config) : [];

        $data = $this->dispatchBeforeWriteEvent($config->table, 'update', $data);

        $dataMap = [$config->table => [$uid => $data]] + $resolved->extraDataMap;
        $writeContext = $this->createWriteContext($request, $config->writeMode);
        $this->writeService->processDataMap($dataMap, $writeContext);

        if ($storedFiles !== []) {
            $this->clearExistingFileReferences($storedFiles, $config, $uid, $writeContext);
            $this->attachFilesToRecord($storedFiles, $config, $uid, $writeContext);
        }

        $this->dispatchAfterWriteEvent($config->table, 'update', $uid);

        $row = $this->dataRepository->findById($config->table, $uid, $config);
        $apiPrefix = (string)$request->getAttribute('tca_api.api_prefix', '/_api');
        $baseUrl = $apiPrefix . '/' . $config->resourceName;

        $serialized = $this->serializer->serialize($row, $config, $baseUrl);
        $serialized = $this->dispatchAfterOperationEvent('update', $serialized);

        return $this->hydraResponseBuilder->buildItem($serialized);
    }
}
