<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\RelationInputResolver;
use MaikSchneider\TcaApi\DataAccess\ResolvedInput;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Validation\FieldValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared write pipeline for CreateHandler and UpdateHandler.
 *
 * Centralises request body parsing (JSON / multipart), upload validation,
 * field validation, relation resolution, column filtering, and file storage
 * so that both handlers compose the same pipeline instead of duplicating it.
 *
 * Also requires the ColumnFilterTrait and FileUploadTrait to be used.
 *
 * @property HydraResponseBuilder $hydraResponseBuilder
 * @property FieldValidator $fieldValidator
 * @property RelationInputResolver $relationResolver
 */
trait WriteOperationTrait
{
    /**
     * Parse the request body from JSON or multipart form data.
     *
     * On success returns ['body' => array, 'uploadedFiles' => array].
     * On failure returns a ResponseInterface (400 or 422).
     *
     * @return array{body: array, uploadedFiles: array}|ResponseInterface
     */
    private function parseBody(ServerRequestInterface $request, ApiDefinition $config): array|ResponseInterface
    {
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

        return ['body' => $body, 'uploadedFiles' => $uploadedFiles];
    }

    /**
     * Validate fields, resolve relation inputs, filter writable columns, and store uploads.
     *
     * On success returns ['data' => array, 'resolved' => ResolvedInput, 'storedFiles' => array].
     * On failure returns a ResponseInterface (422).
     *
     * @return array{data: array, resolved: ResolvedInput, storedFiles: array}|ResponseInterface
     */
    private function validateAndResolve(
        array $body,
        array $uploadedFiles,
        ApiDefinition $config,
        ServerRequestInterface $request,
        bool $partial = false,
    ): array|ResponseInterface {
        $violations = $this->fieldValidator->validate($body, $config, $partial);
        if ($violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($violations);
        }

        $resolved = $this->relationResolver->resolve($body, $config, $config->storagePid ?? 0, $request);
        if ($resolved->violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($resolved->violations);
        }

        $data        = $this->filterWritableColumns($resolved->scalarBody, $config);
        $storedFiles = $uploadedFiles !== [] ? $this->storeUploadedFiles($uploadedFiles, $config) : [];

        return ['data' => $data, 'resolved' => $resolved, 'storedFiles' => $storedFiles];
    }
}
