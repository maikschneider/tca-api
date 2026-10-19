<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\StoragePidResolver;
use MaikSchneider\TcaApi\DataAccess\FileReferenceInputResolver;
use MaikSchneider\TcaApi\DataAccess\RelationInputResolver;
use MaikSchneider\TcaApi\DataAccess\ResolvedInput;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use MaikSchneider\TcaApi\Tca\TceformRestrictions;
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
 * @property FileReferenceInputResolver $fileReferenceResolver
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
        bool $creating = false,
    ): array|ResponseInterface {
        [$recordTypeViolations, $recordType] = $this->resolveRecordType($body, $config, $creating);
        if ($creating && $recordType !== null) {
            $body[$config->recordTypeField] = $recordType;
        }
        $targetType    = $this->targetRecordType($recordType, $config, $request);
        $body          = $this->withoutOtherTypeColumns($body, $config, $targetType);
        $uploadedFiles = $this->withoutOtherTypeColumns($uploadedFiles, $config, $targetType);

        $tceform         = TceformRestrictions::forConfig($config);
        $tceformType     = $tceform !== null ? ($targetType ?? $this->tceformRecordType($body, $config, $request)) : null;
        $disabledColumns = $this->tceformDisabledColumns($config, $tceform, $tceformType);
        $body            = array_diff_key($body, array_flip($disabledColumns));
        $uploadedFiles   = array_diff_key($uploadedFiles, array_flip($disabledColumns));

        $violations = [
            ...$recordTypeViolations,
            ...$this->tceformViolations($body, $config, $tceform, $tceformType),
            ...$this->fieldValidator->validate($body, $config, $partial, $targetType, $disabledColumns),
        ];
        if ($violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($violations);
        }

        // Pulled out before the relation resolver runs: a type=file column stores
        // the reference count, so its input is neither a scalar to write nor a
        // sys_file_reference uid to resolve.
        $linkedFiles = $this->fileReferenceResolver->resolve($body, $config, $request);
        if ($linkedFiles->violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($linkedFiles->violations);
        }

        $resolved = $this->relationResolver->resolve($linkedFiles->body, $config, StoragePidResolver::writePid($config), $request);
        if ($resolved->violations !== []) {
            return $this->hydraResponseBuilder->buildValidationError($resolved->violations);
        }

        $data        = $this->filterWritableColumns($resolved->scalarBody, $config, $targetType);
        if ($creating && $recordType !== null) {
            $data[$config->recordTypeField] = $recordType;
        }
        $storedFiles = $uploadedFiles !== [] ? $this->storeUploadedFiles($uploadedFiles, $config) : [];

        // An uploaded file and a linked one on the same column would fight over the
        // same reference set, so the upload wins and the link is ignored.
        $storedFiles += $linkedFiles->references;

        return ['data' => $data, 'resolved' => $resolved, 'storedFiles' => $storedFiles];
    }

    /**
     * The record type whose columns a write may touch on a resource with type
     * schemas: the type being created or switched to, else the existing record's.
     */
    private function targetRecordType(?string $recordType, ApiDefinition $config, ServerRequestInterface $request): ?string
    {
        if (!$config->typeSchemas) {
            return null;
        }
        if ($recordType !== null) {
            return $recordType;
        }

        $existingRecord = $request->getAttribute('tca_api.existing_record');

        return \is_array($existingRecord) ? $config->recordTypeOf($existingRecord) : null;
    }

    /**
     * Drops TCA columns that are not part of the target type's form, so they are
     * ignored on write like any other unknown input.
     *
     * @template T of array
     * @param T $input
     * @return T
     */
    private function withoutOtherTypeColumns(array $input, ApiDefinition $config, ?string $recordType): array
    {
        if ($recordType === null) {
            return $input;
        }

        $columnMap = $config->columnMap($recordType);
        $tcaColumns = $GLOBALS['TCA'][$config->table]['columns'] ?? [];
        foreach (array_keys($input) as $key) {
            if (isset($tcaColumns[$key]) && !isset($columnMap[$key]) && $key !== $config->recordTypeField) {
                unset($input[$key]);
            }
        }

        return $input;
    }

    /**
     * The record type the TCEFORM types.<type> properties apply to on a resource
     * without type schemas: the type being written, else the existing record's. A
     * type value the resource does not write does not count, so a client cannot
     * pick the rules of another type.
     */
    private function tceformRecordType(array $body, ApiDefinition $config, ServerRequestInterface $request): ?string
    {
        $typeField = $GLOBALS['TCA'][$config->table]['ctrl']['type'] ?? null;
        if (!\is_string($typeField) || $typeField === '' || str_contains($typeField, ':')) {
            return null;
        }
        if (\is_scalar($body[$typeField] ?? null) && array_key_exists($typeField, $this->filterWritableColumns([$typeField => $body[$typeField]], $config))) {
            return (string)$body[$typeField];
        }

        $existingRecord = $request->getAttribute('tca_api.existing_record');

        return \is_array($existingRecord) && \is_scalar($existingRecord[$typeField] ?? null) ? (string)$existingRecord[$typeField] : null;
    }

    /**
     * TCA columns that the storage page's TCEFORM TSconfig disables. Writes ignore
     * them like columns outside the record type, and they are not required.
     *
     * @return list<string>
     */
    private function tceformDisabledColumns(ApiDefinition $config, ?TceformRestrictions $tceform, ?string $recordType): array
    {
        if ($tceform === null) {
            return [];
        }

        $disabled = [];
        foreach (array_keys($GLOBALS['TCA'][$config->table]['columns'] ?? []) as $column) {
            $column = (string)$column;
            if ($column !== $config->recordTypeField && $tceform->isDisabled($column, $recordType)) {
                $disabled[] = $column;
            }
        }

        return $disabled;
    }

    /**
     * Rejects select values that the storage page's TCEFORM TSconfig removes
     * (removeItems, keepItems), including record types on the type field.
     *
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    private function tceformViolations(array $body, ApiDefinition $config, ?TceformRestrictions $tceform, ?string $recordType): array
    {
        if ($tceform === null) {
            return [];
        }

        $typeField = $config->recordTypeField ?? ($GLOBALS['TCA'][$config->table]['ctrl']['type'] ?? null);
        $violations = [];
        foreach ($body as $column => $value) {
            $column = (string)$column;
            if (!isset($GLOBALS['TCA'][$config->table]['columns'][$column]) || !(\is_scalar($value) || \is_array($value))) {
                continue;
            }
            $denied = $tceform->deniedValues($column, $recordType);
            if ($denied === []) {
                continue;
            }
            $values = \is_array($value) ? $value : explode(',', (string)$value);
            foreach ($values as $item) {
                if (\is_scalar($item) && \in_array(trim((string)$item), $denied, true)) {
                    $violations[] = [
                        'propertyPath' => $column,
                        'message'      => sprintf("Value '%s' is not allowed for field '%s' on this storage page.", trim((string)$item), $column),
                        'code'         => $column === $typeField ? 'INVALID_RECORD_TYPE' : 'INVALID_VALUE',
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * Checks the record type in the body against general.recordTypes.
     *
     * Returns the violations and the type a created record gets: the submitted
     * value, or the only allowed type when the client sent none. Updates keep
     * the usual column writability, so their type value is only validated here.
     *
     * @return array{0: list<array{propertyPath: string, message: string, code: string}>, 1: ?string}
     */
    private function resolveRecordType(array $body, ApiDefinition $config, bool $creating): array
    {
        if (!$config->isRecordTypeScoped()) {
            return [[], null];
        }

        $field = $config->recordTypeField;

        if (!\array_key_exists($field, $body)) {
            if (!$creating || $config->fixedRecordType() !== null) {
                return [[], $config->fixedRecordType()];
            }

            return [[[
                'propertyPath' => $field,
                'message'      => sprintf("Field '%s' is required. Allowed: %s", $field, implode(', ', $config->recordTypes)),
                'code'         => 'REQUIRED',
            ]], null];
        }

        $value = $body[$field];
        if (!\is_scalar($value) || !\in_array((string)$value, $config->recordTypes, true)) {
            return [[[
                'propertyPath' => $field,
                'message'      => sprintf("Field '%s' must be one of: %s", $field, implode(', ', $config->recordTypes)),
                'code'         => 'INVALID_RECORD_TYPE',
            ]], null];
        }

        return [[], (string)$value];
    }
}
