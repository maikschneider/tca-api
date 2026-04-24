<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\DataAccess\FileUploadService;
use MaikSchneider\TcaApi\DataAccess\RelationInputResolver;
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

        return $this->doHandle($request, $config, $uid, $partial);
    }

    public function getPriority(): int
    {
        return 10;
    }

    private function doHandle(ServerRequestInterface $request, ApiDefinition $config, int $uid, bool $partial = false): ResponseInterface
    {
        if ($this->dataRepository->findById($config->table, $uid, $config) === null) {
            return $this->hydraResponseBuilder->buildError(404, 'Resource not found.', 'Not Found');
        }

        $isMultipart = str_contains(
            strtolower($request->getHeaderLine('Content-Type')),
            'multipart/',
        );

        if ($isMultipart) {
            $body          = (array)($request->getParsedBody() ?? []);
            $uploadedFiles = $request->getUploadedFiles();

            $violations = $this->validateUploads($uploadedFiles, $config);
            if ($violations !== []) {
                return $this->hydraResponseBuilder->buildValidationError($violations);
            }
        } else {
            $raw = (string)$request->getBody();
            try {
                $body = $raw !== '' ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? []) : [];
            } catch (\JsonException) {
                return $this->hydraResponseBuilder->buildError(400, 'Request body is not valid JSON.', 'Bad Request');
            }
            $uploadedFiles = [];
        }

        $violations = $this->fieldValidator->validate($body, $config, $partial);
        if ($violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($violations);
        }

        // Resolve relation fields. Security + validation on nested child objects
        // is enforced inside resolve(); violations bubble up here.
        $resolved = $this->relationResolver->resolve($body, $config, $config->storagePid ?? 0, $request);
        if ($resolved->violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($resolved->violations);
        }

        $data = $this->filterWritableColumns($resolved->scalarBody, $config);

        // Store uploaded files in FAL now (validation already passed).
        // Absent file fields in PATCH leave existing references untouched (PATCH semantics).
        $storedFiles = $uploadedFiles !== [] ? $this->storeUploadedFiles($uploadedFiles, $config) : [];

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

        return $this->hydraResponseBuilder->buildItem(
            $this->serializer->serialize($row, $config, $baseUrl),
        );
    }
}
