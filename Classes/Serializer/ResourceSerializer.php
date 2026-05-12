<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\Cache\CacheTagCollector;
use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use TYPO3\CMS\Core\Schema\Field\FileFieldType;
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

    public function __construct(
        private readonly TcaSchemaFactory $schemaFactory,
        private readonly CacheTagCollector $cacheTagCollector,
        private readonly FileFieldSerializer $fileFieldSerializer,
        private readonly RelationSerializer $relationSerializer,
    ) {
    }

    /**
     * Serialize a single raw DB row.
     *
     * @param array  $preloaded      ['rows' => [foreignTable => [uid => row]], 'relations' => [column => [parentUid => [uid, ...]]]]
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
        $uid       = (int)$row['uid'];
        $schema    = $this->getSchema($config->table);
        $columnMap = $this->resolveColumnMap($config);

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

            if ($field instanceof FileFieldType) {
                $result[$column] = $this->fileFieldSerializer->serialize($column, $field, $columnDef, $config->table, $uid);
                continue;
            }

            if (!($field instanceof RelationalFieldTypeInterface)) {
                $value = $row[$column] ?? null;
                if ($columnDef->processor === null && ($field->getConfiguration()['type'] ?? '') === 'imageManipulation') {
                    $value = $this->decodeJsonValue($value);
                }
                $result[$column] = $this->applyColumnProcessor($value, $columnDef, $result, $row);
                continue;
            }

            if ($field->getRelationshipType()->hasOne()) {
                $propertyName          = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;
                $result[$propertyName] = $this->relationSerializer->serializeHasOne($column, $columnDef, $config, $row, $field, $preloaded, $remainingDepth, $visited, $operation, $apiPrefix, $this);
                continue;
            }

            $result[$column] = $this->relationSerializer->serializeHasManyField($column, $columnDef, $config, $row, $field, $preloaded, $remainingDepth, $visited, $operation, $apiPrefix, $this);
        }

        foreach ($config->virtualProperties as $virtualPropertyName => $virtualPropDef) {
            if ($config->isExplicitMode && !$virtualPropDef->isReadable($operation)) {
                continue;
            }

            $columnRef   = $virtualPropDef->column;
            $columnField = null;
            if ($columnRef !== null && $schema->hasField($columnRef)) {
                $columnField = $schema->getField($columnRef);
            }

            if ($columnField instanceof FileFieldType) {
                $result[$virtualPropertyName] = $this->fileFieldSerializer->serialize($columnRef, $columnField, $virtualPropDef, $config->table, $uid);
            } elseif ($virtualPropDef->processor !== null) {
                $value = $columnRef !== null ? ($row[$columnRef] ?? null) : null;
                $result[$virtualPropertyName] = $this->applyColumnProcessor($value, $virtualPropDef, $result, $row);
            } else {
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
        return array_map(
            fn (array $row) => $this->serialize($row, $config, $baseUrl, $fields, $preloaded, -1, [], $operation),
            $rows,
        );
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

    private function applyColumnProcessor(mixed $value, ColumnDefinition $columnDef, array $serializedRow, array $rawRow): mixed
    {
        if ($columnDef->processor === null) {
            return $value;
        }

        /** @var class-string<ColumnProcessorInterface> $processorClass */
        $processorClass = $columnDef->processor;
        $processor = GeneralUtility::makeInstance($processorClass);

        return $processor->process($value, $columnDef, ['serializedRow' => $serializedRow, 'rawRow' => $rawRow]);
    }

    /**
     * Decode a JSON string to an associative array.
     *
     * Returns null when the input is null, the decoded array on valid JSON,
     * or the original raw string when decoding fails.
     */
    private function decodeJsonValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === \JSON_ERROR_NONE ? $decoded : $value;
    }

    /** Returns the TcaSchema for a table, cached to avoid repeated factory calls per collection row. */
    private function getSchema(string $table): TcaSchema
    {
        return $this->schemaCache[$table] ??= $this->schemaFactory->get($table);
    }
}
