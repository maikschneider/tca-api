<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\Cache\CacheTagCollector;
use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\PreloadingProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\ProcessorGuard;
use MaikSchneider\TcaApi\Serializer\Processing\TypoLinkProcessor;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use TYPO3\CMS\Core\Schema\Field\FileFieldType;
use TYPO3\CMS\Core\Schema\Field\FlexFormFieldType;
use TYPO3\CMS\Core\Schema\Field\JsonFieldType;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Serializes a TCA domain record to a Hydra JSON-LD array.
 *
 * Acts as the orchestrator that delegates to focused collaborators:
 * - FileFieldSerializer   — type=file columns
 * - RelationSerializer    — hasOne and hasMany relations
 * - GroupFieldSerializer   — type=group fields (via RelationSerializer)
 *
 * All data is read directly from raw DB rows — no RecordFactory, no RecordInterface.
 * The Schema API (TcaSchemaFactory) is used to introspect field types and relationship
 * cardinality. File references are resolved via FileRepository::findByRelation().
 *
 * Embed config (per column):
 *   'embed' => true              — embed full related record at depth 1
 *   'embed' => ['depth' => N]   — embed N levels deep
 *   (no 'embed' key)            — return shallow stub {@id, @type, uid}  [default]
 */
final class ResourceSerializer
{
    /** @var array<string, TcaSchema> Schemas cached per table to avoid repeated factory calls during collection serialization. */
    private array $schemaCache = [];

    /** @var array<string, array<string, ColumnDefinition>> Column maps cached per table+mode to avoid rebuilding on every row in a collection. */
    private array $columnMapCache = [];

    /** @var array<int, array<class-string, array{processor: ColumnProcessorInterface, uids: array<int, true>}>> Processors prepared for the current collection, per API definition. */
    private array $preparedProcessors = [];

    public function __construct(
        private readonly TcaSchemaFactory $schemaFactory,
        private readonly CacheTagCollector $cacheTagCollector,
        private readonly FileFieldSerializer $fileFieldSerializer,
        private readonly RelationSerializer $relationSerializer,
        private readonly ProcessorGuard $processorGuard,
        private readonly DateTimeValueFormatter $dateTimeValueFormatter = new DateTimeValueFormatter(),
    ) {
    }

    /**
     * Serialize a single raw DB row.
     *
     * @param array  $preloaded      ['rows' => [foreignTable => [uid => row]], 'relations' => [column => [parentUid => [uid, ...]]], 'files' => [table => PreloadedFileReferences]]
     * @param int    $remainingDepth -1 = top level (use per-column embed config); ≥0 = recursive budget from parent embed
     * @param array  $visited        ['table:uid' => true] cycle-prevention guard
     * @param string $operation      Current operation context: 'list', 'show', 'create', 'update', or '' for default
     */
    public function serialize(
        array $row,
        ApiDefinition $config,
        string $baseUrl,
        array $fields = [],
        array $preloaded = [],
        int $remainingDepth = -1,
        array $visited = [],
        string $operation = '',
    ): array {
        $uid           = (int)$row['uid'];
        $schema        = $this->getSchema($config->table);
        $columnMap     = $this->resolveColumnMap($config);
        $preloadedFiles = $preloaded['files'][$config->table] ?? null;

        // The base '{table}' tag is added by RequestDispatcher at cache activation
        $this->cacheTagCollector->addTag($config->table . '_' . $uid);

        // Derive the API prefix from $baseUrl by stripping the resource name portion.
        // e.g. '/_api/articles' → '/_api', '/custom-api/articles' → '/custom-api'
        $apiPrefix = \strlen($config->resourceName) > 0
            ? rtrim(substr($baseUrl, 0, \strlen($baseUrl) - \strlen($config->resourceName)), '/')
            : rtrim($baseUrl, '/');

        $result = [
            '@type' => $config->resourceType,
            '@id'   => $baseUrl . '/' . $uid,
            'uid'   => $uid,
        ];

        foreach ($columnMap as $column => $columnDef) {
            if ($config->isExplicitMode && !$columnDef->isReadable($operation)) {
                continue;
            }
            if ($fields !== [] && !\in_array($column, $fields, true)) {
                continue;
            }
            if (!$schema->hasField($column)) {
                continue;
            }

            $field = $schema->getField($column);

            // Password columns must never appear in API responses, even when explicitly configured.
            if (($GLOBALS['TCA'][$config->table]['columns'][$column]['config']['type'] ?? '') === 'password') {
                continue;
            }

            if ($field instanceof FileFieldType) {
                $result[$column] = $this->fileFieldSerializer->serialize($column, $field, $columnDef, $config->table, $uid, $preloadedFiles);
                continue;
            }

            if (!($field instanceof RelationalFieldTypeInterface)) {
                $value = $row[$column] ?? null;

                $isProcessorDefined = $columnDef->processor !== null;

                // preprocess FlexForm XML data
                if (!$isProcessorDefined && $field instanceof FlexFormFieldType) {
                    $value = $this->decodeFlexFormValue($value);
                }

                // Format datetime fields to ISO 8601
                if (!$isProcessorDefined && ($field->getConfiguration()['type'] ?? '') === 'datetime') {
                    $dbType = $field->getConfiguration()['dbType'] ?? null;
                    $value = $this->dateTimeValueFormatter->format($value, $dbType);
                }

                // preprocess JSON data
                $isJsonField = $field instanceof JsonFieldType || ($field->getConfiguration()['type'] ?? '') === 'imageManipulation';
                if (!$isProcessorDefined && $isJsonField) {
                    $value = $this->decodeJsonValue($value);
                }

                // Auto-apply TypoLinkProcessor for type=link columns without explicit processor
                $isLinkField = ($GLOBALS['TCA'][$config->table]['columns'][$column]['config']['type'] ?? '') === 'link';
                if (!$isProcessorDefined && $isLinkField) {
                    $processor = $this->processorGuard->instantiate(TypoLinkProcessor::class, $config->table, $column, $uid);
                    $result[$column] = $processor instanceof TypoLinkProcessor
                        ? $this->processorGuard->run(
                            static fn () => $processor->process($value, $columnDef, ['serializedRow' => $result, 'rawRow' => $row]),
                            TypoLinkProcessor::class,
                            $config->table,
                            $column,
                            $uid,
                        )
                        : null;
                    continue;
                }

                $result[$column] = $this->applyColumnProcessor($value, $columnDef, $result, $row, $config, $column, $uid);
                continue;
            }

            if ($field->getRelationshipType()->hasOne()) {
                $result[$column] = $this->relationSerializer->serializeHasOne($column, $columnDef, $config, $row, $field, $preloaded, $remainingDepth, $visited, $operation, $apiPrefix, $this);
                continue;
            }

            $result[$column] = $this->relationSerializer->serializeHasManyField($column, $columnDef, $config, $row, $field, $preloaded, $remainingDepth, $visited, $operation, $apiPrefix, $this);
        }

        // Column callbacks run after all columns and relations are resolved
        foreach ($columnMap as $column => $columnDef) {
            if ($columnDef->callback === null) {
                continue;
            }
            if ($config->isExplicitMode && !$columnDef->isReadable($operation)) {
                continue;
            }
            if ($fields !== [] && !\in_array($column, $fields, true)) {
                continue;
            }

            /** @var array{class-string, string} $callback */
            $callback = $columnDef->callback;
            [$class, $method] = $callback;
            $result[$column] = GeneralUtility::makeInstance($class)->$method($result, $row);
        }

        foreach ($config->virtualProperties as $virtualPropertyName => $virtualPropDef) {
            if ($config->isExplicitMode && !$virtualPropDef->isReadable($operation)) {
                continue;
            }
            if ($fields !== [] && !\in_array($virtualPropertyName, $fields, true)) {
                continue;
            }

            $columnRef   = $virtualPropDef->column;
            $columnField = null;
            if ($columnRef !== null && $schema->hasField($columnRef)) {
                $columnField = $schema->getField($columnRef);
            }

            // Establish the base value from a file column or processor, if defined.
            if ($columnField instanceof FileFieldType) {
                $result[$virtualPropertyName] = $this->fileFieldSerializer->serialize($columnRef, $columnField, $virtualPropDef, $config->table, $uid, $preloadedFiles);
            } elseif ($virtualPropDef->processor !== null) {
                $value = $columnRef !== null ? ($row[$columnRef] ?? null) : null;
                $result[$virtualPropertyName] = $this->applyColumnProcessor($value, $virtualPropDef, $result, $row, $config, $virtualPropertyName, $uid);
            }

            // A callback, when present, always runs last
            if ($virtualPropDef->callback !== null) {
                /** @var array{class-string, string} $callback */
                $callback = $virtualPropDef->callback;
                [$class, $method] = $callback;
                $result[$virtualPropertyName] = GeneralUtility::makeInstance($class)->$method($result, $row);
            }
        }

        return $result;
    }

    public function serializeCollection(
        array $rows,
        ApiDefinition $config,
        string $baseUrl,
        array $fields = [],
        array $preloaded = [],
        string $operation = '',
    ): array {
        // Saved and restored so a prepared batch cannot outlive its collection:
        // this serializer is a shared service, and a later single-record
        // serialize() must fall back to an unprepared processor.
        $outer = $this->preparedProcessors;

        try {
            $this->preparedProcessors = [];
            $this->prepareProcessors($rows, $config, $fields, $operation);

            return array_map(
                fn (array $row) => $this->serialize($row, $config, $baseUrl, $fields, $preloaded, -1, [], $operation),
                $rows,
            );
        } finally {
            $this->preparedProcessors = $outer;
        }
    }

    /**
     * Give every PreloadingProcessorInterface the whole page before serialization
     * starts, so a processor doing its own lookup can batch it instead of paying
     * per row.
     *
     * Only processors that will actually run are prepared: a column hidden by
     * groups, dropped by a sparse fieldset, or serialized through the file branch
     * never reaches process(), and must not cause a preload query either.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function prepareProcessors(array $rows, ApiDefinition $config, array $fields, string $operation): void
    {
        if ($rows === []) {
            return;
        }

        $definitions  = $this->resolveColumnMap($config) + $config->virtualProperties;
        $schema       = $this->getSchema($config->table);
        $prepared     = [];
        $preparedUids = [];
        foreach ($rows as $row) {
            $preparedUids[(int)$row['uid']] = true;
        }

        foreach ($definitions as $name => $definition) {
            $processorClass = $definition->processor;

            if ($processorClass === null || isset($prepared[$processorClass])) {
                continue;
            }
            if ($config->isExplicitMode && !$definition->isReadable($operation)) {
                continue;
            }
            if ($fields !== [] && !\in_array($name, $fields, true)) {
                continue;
            }

            // FileFieldSerializer owns type=file columns, and a virtual property
            // sourcing one goes the same way, so the column processor never runs.
            $sourceColumn = $definition->column ?? $name;
            if ($schema->hasField($sourceColumn) && $schema->getField($sourceColumn) instanceof FileFieldType) {
                continue;
            }
            if (!is_a($processorClass, PreloadingProcessorInterface::class, true)
                || !is_a($processorClass, ColumnProcessorInterface::class, true)
            ) {
                continue;
            }

            $prepared[$processorClass] = true;
            $instance = $this->processorGuard->instantiate($processorClass, $config->table, (string)$name, 0);
            if (!$instance instanceof PreloadingProcessorInterface) {
                continue;
            }

            $processor = $this->processorGuard->run(
                static function () use ($instance, $rows, $config): ColumnProcessorInterface {
                    /** @var ColumnProcessorInterface&PreloadingProcessorInterface $instance */
                    $instance->prepare($rows, $config);

                    return $instance;
                },
                $processorClass,
                $config->table,
                (string)$name,
                0,
            );
            if (!$processor instanceof ColumnProcessorInterface) {
                continue;
            }

            // Scope the instance to this exact API definition and the rows passed
            // to prepare(). A same-table embedded record outside the page must use
            // a cold processor rather than read an unrelated prepared batch.
            $this->preparedProcessors[spl_object_id($config)][$processorClass] = [
                'processor' => $processor,
                'uids'      => $preparedUids,
            ];
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build the column map for iteration in serialize().
     *
     * Explicit mode: returns $config->columns as-is.
     * Default mode: returns all exposable TCA columns, using $config->columns overrides where set.
     *
     * Result is cached per table+mode to avoid rebuilding on every row during collection serialization.
     *
     * @return array<string, ColumnDefinition>
     */
    private function resolveColumnMap(ApiDefinition $config): array
    {
        $cacheKey = $config->table . $config->resourceName . ($config->isExplicitMode ? ':explicit' : ':default');

        if (isset($this->columnMapCache[$cacheKey])) {
            return $this->columnMapCache[$cacheKey];
        }

        if ($config->isExplicitMode) {
            return $this->columnMapCache[$cacheKey] = $config->columns;
        }

        $columnMap = [];
        foreach (TcaColumnDiscovery::getExposableColumnNames($config->table) as $colName) {
            $columnMap[$colName] = $config->columns[$colName] ?? new ColumnDefinition(groups: null);
        }

        return $this->columnMapCache[$cacheKey] = $columnMap;
    }

    private function applyColumnProcessor(
        mixed $value,
        ColumnDefinition $columnDef,
        array $serializedRow,
        array $rawRow,
        ApiDefinition $apiConfig,
        string $column,
        int $uid,
    ): mixed {
        if ($columnDef->processor === null) {
            return $value;
        }

        /** @var class-string<ColumnProcessorInterface> $processorClass */
        $processorClass = $columnDef->processor;
        $prepared = $this->preparedProcessors[spl_object_id($apiConfig)][$processorClass] ?? null;

        $processor = $prepared !== null && isset($prepared['uids'][$uid])
            ? $prepared['processor']
            : $this->processorGuard->instantiate($processorClass, $apiConfig->table, $column, $uid);
        if (!$processor instanceof ColumnProcessorInterface) {
            return null;
        }

        return $this->processorGuard->run(
            static fn () => $processor->process($value, $columnDef, ['serializedRow' => $serializedRow, 'rawRow' => $rawRow]),
            $processorClass,
            $apiConfig->table,
            $column,
            $uid,
        );
    }

    /** Returns the TcaSchema for a table, cached to avoid repeated factory calls per collection row. */
    private function getSchema(string $table): TcaSchema
    {
        return $this->schemaCache[$table] ??= $this->schemaFactory->get($table);
    }

    /**
     * Decode a JSON string from the database into a PHP array/value.
     * Returns null for null input, falls back to the raw string on invalid JSON.
     */
    private function decodeJsonValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true, 512);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        return $decoded;
    }

    /**
     * Decode FlexForm XML into an associative array.
     * Returns null for empty input, original if invalid
     */
    private function decodeFlexFormValue(mixed $value): array|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!\is_string($value)) {
            return null;
        }

        $decoded = GeneralUtility::xml2array($value);

        if (!\is_array($decoded)) {
            return $value;
        }

        return $decoded;
    }
}
