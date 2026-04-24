<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\DataAccess\FileUploadService;
use MaikSchneider\TcaApi\DataAccess\RelationInputResolver;
use MaikSchneider\TcaApi\DataAccess\RelationResolutionResult;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use MaikSchneider\TcaApi\Event\AfterWriteEvent;
use MaikSchneider\TcaApi\Event\BeforeWriteEvent;
use MaikSchneider\TcaApi\Security\WriteContext;
use MaikSchneider\TcaApi\Security\WriteContextFactory;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Serializer\ResourceSerializer;
use MaikSchneider\TcaApi\Validation\FieldValidator;
use MaikSchneider\TcaApi\Validation\UploadValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared write pipeline for CreateHandler and UpdateHandler.
 *
 * Encapsulates the common flow: body parsing → validation → relation resolution →
 * file uploads → BeforeWriteEvent → DataHandler → AfterWriteEvent → response.
 *
 * Requires the using class to have the following constructor-injected dependencies:
 * - DataWriteService $writeService
 * - DataRepository $dataRepository
 * - ResourceSerializer $serializer
 * - HydraResponseBuilder $hydraResponseBuilder
 * - FieldValidator $fieldValidator
 * - EventDispatcherInterface $eventDispatcher
 * - RelationInputResolver $relationResolver
 * - WriteContextFactory $writeContextFactory
 * - FileUploadService $fileUploadService
 * - UploadValidator $uploadValidator
 */
trait WriteHandlerTrait
{
    use ColumnFilterTrait;
    use FileUploadTrait;

    private DataWriteService $writeService;
    private DataRepository $dataRepository;
    private ResourceSerializer $serializer;
    private HydraResponseBuilder $hydraResponseBuilder;
    private FieldValidator $fieldValidator;
    private EventDispatcherInterface $eventDispatcher;
    private RelationInputResolver $relationResolver;
    private WriteContextFactory $writeContextFactory;
    private FileUploadService $fileUploadService;
    private UploadValidator $uploadValidator;

    /**
     * Parses the request body and handles both JSON and multipart/form-data content types.
     *
     * @return array{body: array, uploadedFiles: array<string, mixed>}
     */
    private function parseRequestBody(ServerRequestInterface $request): array
    {
        $isMultipart = str_contains(
            strtolower($request->getHeaderLine('Content-Type')),
            'multipart/',
        );

        if ($isMultipart) {
            $body = (array)($request->getParsedBody() ?? []);
            $uploadedFiles = $request->getUploadedFiles();
        } else {
            $raw = (string)$request->getBody();
            try {
                $body = $raw !== '' ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? []) : [];
            } catch (\JsonException) {
                return [
                    'error' => $this->hydraResponseBuilder->buildError(
                        400,
                        'Request body is not valid JSON.',
                        'Bad Request',
                    ),
                ];
            }
            $uploadedFiles = [];
        }

        return ['body' => $body, 'uploadedFiles' => $uploadedFiles, 'isMultipart' => $isMultipart];
    }

    /**
     * Validates uploaded files against column constraints.
     *
     * @param array<string, mixed> $uploadedFiles
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    private function validateUploadedFiles(array $uploadedFiles, ApiDefinition $config): array
    {
        if ($uploadedFiles === []) {
            return [];
        }

        return $this->validateUploads($uploadedFiles, $config);
    }

    /**
     * Resolves relation fields and returns the result or a validation error response.
     *
     * @return RelationResolutionResult|ResponseInterface Resolution result on success, ResponseInterface on error
     */
    private function resolveRelations(
        array $body,
        ApiDefinition $config,
        ServerRequestInterface $request,
    ): RelationResolutionResult|ResponseInterface {
        $resolved = $this->relationResolver->resolve($body, $config, $config->storagePid ?? 0, $request);
        if ($resolved->violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($resolved->violations);
        }

        return $resolved;
    }

    /**
     * Prepares ownership data for new records.
     *
     * @return array<string, mixed>
     */
    private function prepareOwnershipData(ServerRequestInterface $request, ApiDefinition $config): array
    {
        $data = [];
        $feUser = $request->getAttribute('frontend.user');

        if ($feUser !== null && !empty($feUser->user['uid'])) {
            $feUid = (int)$feUser->user['uid'];
            if ($config->ownershipColumn !== null) {
                $data[$config->ownershipColumn] = $feUid;
            }
            if ($config->ownershipSetOnCreate !== null && $config->ownershipSetOnCreate !== $config->ownershipColumn) {
                $data[$config->ownershipSetOnCreate] = $feUid;
            }
        }

        return $data;
    }

    /**
     * Processes file uploads and returns stored file references.
     *
     * @param array<string, mixed> $uploadedFiles
     * @return array<string, array<string, array>>
     */
    private function processFileUploads(array $uploadedFiles, ApiDefinition $config): array
    {
        if ($uploadedFiles === []) {
            return [];
        }

        return $this->storeUploadedFiles($uploadedFiles, $config);
    }

    /**
     * Dispatches BeforeWriteEvent and returns potentially modified data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function dispatchBeforeWriteEvent(string $table, string $operation, array $data): array
    {
        $beforeEvent = new BeforeWriteEvent($table, $operation, $data);
        $this->eventDispatcher->dispatch($beforeEvent);

        return $beforeEvent->getData();
    }

    /**
     * Dispatches AfterWriteEvent.
     */
    private function dispatchAfterWriteEvent(string $table, string $operation, int $uid): void
    {
        $this->eventDispatcher->dispatch(new AfterWriteEvent($table, $operation, $uid));
    }

    /**
     * Dispatches AfterOperationEvent with the serialized data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> Potentially modified data from event listeners
     */
    private function dispatchAfterOperationEvent(string $operation, array $data): array
    {
        $event = new AfterOperationEvent($operation, $data);
        $this->eventDispatcher->dispatch($event);

        return $event->getData();
    }

    /**
     * Creates a WriteContext from the request.
     */
    private function createWriteContext(ServerRequestInterface $request, ?\MaikSchneider\TcaApi\Enum\WriteMode $writeMode): WriteContext
    {
        return $this->writeContextFactory->fromRequest($request, $writeMode ?? \MaikSchneider\TcaApi\Enum\WriteMode::ACTING_USER);
    }

    /**
     * Attaches file references to a record after it has been created/updated.
     *
     * @param array<string, array<string, array>> $storedFiles
     */
    private function attachFilesToRecord(
        array $storedFiles,
        ApiDefinition $config,
        int $uid,
        WriteContext $writeContext,
    ): void {
        if ($storedFiles !== [] && $uid > 0) {
            $this->attachFileReferences($storedFiles, $config, $uid, $writeContext);
        }
    }

    /**
     * Deletes existing file references before attaching new ones (for update operations).
     *
     * @param array<string, array<string, array>> $storedFiles
     */
    private function clearExistingFileReferences(
        array $storedFiles,
        ApiDefinition $config,
        int $uid,
        WriteContext $writeContext,
    ): void {
        if ($storedFiles !== []) {
            $this->deleteExistingFileReferences($storedFiles, $config, $uid, $writeContext);
        }
    }
}
