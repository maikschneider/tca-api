<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Loader\TcaValidatorDeriver;
use MaikSchneider\TcaApi\Serializer\Processing\RouteEnhancerProcessor;
use MaikSchneider\TcaApi\Serializer\Processing\TypoLinkProcessor;
use MaikSchneider\TcaApi\Serializer\RelationSerializer;
use MaikSchneider\TcaApi\Tca\RecordTypeFields;
use MaikSchneider\TcaApi\Tca\RelationCardinality;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class OpenApiSchemasBuilder
{
    /** @param ApiDefinition[] $resources */
    public function build(array $resources): array
    {
        $schemas = [
            'RelationStub' => [
                'type' => 'object',
                'properties' => [
                    '@id'   => ['type' => 'string'],
                    '@type' => ['type' => 'string'],
                    'uid'   => ['type' => 'integer'],
                ],
                'required' => ['@id', '@type', 'uid'],
            ],
            // What FileProcessor writes, plus the fields ImageProcessor adds: alternative and
            // copyright, and either width/height (with a cropVariant) or cropVariants.
            'FileObject' => [
                'type' => 'object',
                'properties' => [
                    'uid'       => ['type' => 'integer'],
                    'name'      => ['type' => 'string'],
                    'extension' => ['type' => 'string'],
                    // null for a file in a non-public storage
                    'publicUrl' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                    'mimeType'  => ['type' => 'string'],
                    'fileSize'  => ['type' => 'integer'],
                    'metadata'  => [
                        'type' => 'object',
                        'properties' => [
                            'title'       => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                            'alternative' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                            'description' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                            'copyright'   => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                        ],
                    ],
                    'width'        => ['type' => 'integer'],
                    'height'       => ['type' => 'integer'],
                    'cropVariants' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'object',
                            'properties' => [
                                'publicUrl' => ['type' => 'string'],
                                'width'     => ['type' => 'integer'],
                                'height'    => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
            'HydraError' => [
                'type' => 'object',
                'properties' => [
                    '@context' => ['type' => 'string', 'example' => 'http://www.w3.org/ns/hydra/context.jsonld'],
                    '@type' => ['type' => 'string', 'example' => 'hydra:Error'],
                    'hydra:title' => ['type' => 'string'],
                    'hydra:description' => ['type' => 'string'],
                ],
                'required' => ['@type', 'hydra:title', 'hydra:description'],
            ],
            'ValidationError' => [
                'type' => 'object',
                'properties' => [
                    '@context' => ['type' => 'string', 'example' => 'http://www.w3.org/ns/hydra/context.jsonld'],
                    '@type' => ['type' => 'string', 'example' => 'hydra:Error'],
                    'hydra:title' => ['type' => 'string', 'example' => 'Validation Failed'],
                    'hydra:description' => ['type' => 'string'],
                    'violations' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'propertyPath' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                                'code' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $names = new OpenApiSchemaNames($resources);
        foreach ($resources as $config) {
            $name = $names->of($config);
            $schemas += $config->typeSchemas
                ? $this->buildTypeReadSchemas($config, $resources, $names, $schemas)
                : [$name . 'Read' => $this->withRecordTypeEnum($this->buildReadSchema($config, $resources), $config, false)];
            if ($this->hasWriteOperations($config)) {
                $schemas[$name . 'Write'] = $this->withRecordTypeEnum($this->buildWriteSchema($config), $config, true);
            }
            $schemas[$name . 'Collection'] = $this->buildCollectionSchema($name);
            if ($this->hasUploadColumns($config)) {
                $schemas[$name . 'WriteMultipart'] = $this->withRecordTypeEnum($this->buildMultipartWriteSchema($config), $config, true);
            }
        }

        return $schemas;
    }

    /**
     * @param ApiDefinition[] $resources
     * @param int             $remainingDepth -1 for a resource's own schema, else the embed depth left, as in ResourceSerializer::serialize()
     * @param string|null     $recordType     on a resource with type schemas, the record type to describe; null = all types
     * @param list<string>    $ancestorTables tables of the records this one is embedded in, outermost first
     */
    private function buildReadSchema(ApiDefinition $config, array $resources, int $remainingDepth = -1, ?string $recordType = null, array $ancestorTables = []): array
    {
        $properties = [
            '@type' => ['type' => 'string', 'example' => $config->resourceType],
            '@id' => ['type' => 'string'],
            'uid' => ['type' => 'integer'],
        ];

        if (!$config->isExplicitMode) {
            foreach ($config->columnMap($recordType) as $column => $columnDef) {
                $tcaConfig = RecordTypeFields::columnDefinition($config->table, $column, $recordType)['config'] ?? null;
                $properties[$column] = $this->buildReadPropertySchema($columnDef, $tcaConfig, $config, $resources, $remainingDepth, $ancestorTables);
            }
        } else {
            foreach ($config->columns as $column => $columnDef) {
                if (!$columnDef->isReadable()) {
                    continue;
                }
                if (self::isPasswordColumn($config->table, $column)) {
                    continue;
                }
                $tcaConfig = $GLOBALS['TCA'][$config->table]['columns'][$column]['config'] ?? null;
                $properties[$column] = $this->buildReadPropertySchema($columnDef, $tcaConfig, $config, $resources, $remainingDepth, $ancestorTables);
            }
        }

        foreach ($config->virtualProperties as $vpName => $vpDef) {
            if ($config->isExplicitMode && !$vpDef->isReadable()) {
                continue;
            }
            $properties[$vpName] = $this->buildVirtualPropertySchema($vpDef, $config);
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    /**
     * A virtual property over a file column is serialized like the column itself,
     * unless a callback, which runs last, replaces the value.
     */
    private function buildVirtualPropertySchema(ColumnDefinition $vpDef, ApiDefinition $config): array
    {
        $sourceConfig = $vpDef->column !== null ? ($GLOBALS['TCA'][$config->table]['columns'][$vpDef->column]['config'] ?? null) : null;
        if ($vpDef->callback === null && \is_array($sourceConfig) && ($sourceConfig['type'] ?? null) === 'file') {
            return $this->buildFilePropertySchema($sourceConfig);
        }
        if ($vpDef->callback === null && $vpDef->type === null) {
            return self::processorSchema($vpDef->processor) ?? $this->buildPropertySchema($vpDef);
        }

        return $this->buildPropertySchema($vpDef);
    }

    /**
     * Output of the extension's own processors: TypoLinkProcessor (also applied to
     * type=link columns without a processor) and RouteEnhancerProcessor send a URL,
     * or null when there is none. Other processors' output is only known to their author.
     */
    private static function processorSchema(?string $processor): ?array
    {
        return \in_array($processor, [TypoLinkProcessor::class, RouteEnhancerProcessor::class], true)
            ? ['oneOf' => [['type' => 'string'], ['type' => 'null']]]
            : null;
    }

    /**
     * Read schemas of a resource with type schemas: one per record type, with the
     * type field fixed to that type. A single-type resource's type schema is its
     * Read schema; a multi-type resource's Read schema is a oneOf over the type
     * schemas with a discriminator on the type field.
     *
     * @param ApiDefinition[]       $resources
     * @param array<string, mixed>  $built     component schemas built so far, whose names are taken
     * @return array<string, array<string, mixed>>
     */
    private function buildTypeReadSchemas(ApiDefinition $config, array $resources, OpenApiSchemaNames $names, array $built): array
    {
        $field = (string)$config->recordTypeField;
        $recordTypes = $config->recordTypes ?? [];

        $base = $names->of($config);
        $schemas = [];
        $mapping = [];
        // Other resources' Read schemas and the schemas built so far keep their names,
        // e.g. those of resources split from this one or type schemas of another resource.
        $taken = array_fill_keys(array_keys($built), true);
        foreach ($resources as $resource) {
            if ($resource !== $config) {
                $taken[$names->of($resource) . 'Read'] = true;
            }
        }
        $suffixes = [];
        foreach ($recordTypes as $recordType) {
            $schema = $this->buildReadSchema($config, $resources, -1, $recordType);
            $schema['properties'][$field] = self::recordTypeEnum($schema['properties'][$field] ?? ['type' => 'string'], [$recordType]);
            $schema['required'] = [$field];

            $name = $base . 'Read';
            if (\count($recordTypes) > 1) {
                $suffix = self::uniqueRecordTypeSchemaSuffix($recordType, $suffixes);
                for ($counter = 2, $unique = $suffix; isset($taken[$base . $unique . 'Read']); $counter++) {
                    $unique = $suffix . $counter;
                }
                $suffixes[$unique] = true;
                $name = $base . $unique . 'Read';
            }
            $taken[$name] = true;
            $schemas[$name] = $schema;
            $mapping[$recordType] = '#/components/schemas/' . $name;
        }

        if (\count($recordTypes) > 1) {
            $schemas[$base . 'Read'] = [
                'oneOf' => array_map(static fn (string $ref): array => ['$ref' => $ref], array_values($mapping)),
                'discriminator' => ['propertyName' => $field, 'mapping' => (object)$mapping],
            ];
        }

        return $schemas;
    }

    /**
     * 'textmedia' → 'Textmedia', 'menu_pages' → 'MenuPages', '254' → '254'. Types
     * that differ only in separators get a counter: 'foo_bar' → 'FooBar',
     * 'foo-bar' → 'FooBar2'.
     *
     * @param array<string, true> $used suffixes taken so far, updated in place
     */
    private static function uniqueRecordTypeSchemaSuffix(string $recordType, array &$used): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $recordType, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $base = implode('', array_map('ucfirst', $parts));

        $suffix = $base;
        for ($counter = 2; isset($used[$suffix]); $counter++) {
            $suffix = $base . $counter;
        }
        $used[$suffix] = true;

        return $suffix;
    }

    /**
     * @param ApiDefinition[] $resources
     * @param list<string>    $ancestorTables
     */
    private function buildReadPropertySchema(ColumnDefinition $columnDef, ?array $tcaConfig, ApiDefinition $config, array $resources, int $remainingDepth = -1, array $ancestorTables = []): array
    {
        // A column callback runs after everything else and replaces the value.
        if ($columnDef->callback !== null) {
            return $this->buildPropertySchema($columnDef);
        }

        if ($tcaConfig !== null) {
            $tcaType = $tcaConfig['type'] ?? '';

            if ($tcaType === 'file') {
                return $this->buildFilePropertySchema($tcaConfig);
            }
            if ($this->isHasManyField($tcaType, $tcaConfig)) {
                $related = $this->relatedRecordSchema($tcaConfig, $columnDef, $config, $resources, $remainingDepth, $ancestorTables, false);
                return $related !== null
                    ? ['type' => 'array', 'items' => $related]
                    : $this->buildHasManySchema($columnDef, $this->referenceSchema($tcaConfig, $columnDef, $config, $resources));
            }
            if ($this->isHasOneField($tcaType, $tcaConfig)) {
                $related = $this->relatedRecordSchema($tcaConfig, $columnDef, $config, $resources, $remainingDepth, $ancestorTables, true);
                return $related !== null
                    ? ['oneOf' => [$related, ['type' => 'null']]]
                    : $this->buildHasOneSchema($columnDef, $this->referenceSchema($tcaConfig, $columnDef, $config, $resources));
            }
            $processorSchema = $columnDef->type === null ? self::processorSchema($columnDef->processor) : null;
            if ($processorSchema !== null) {
                return $processorSchema;
            }
            // A declared type wins, and any other processor's output is only known to its author.
            if ($columnDef->type === null && $columnDef->processor === null) {
                $valueSchema = ColumnValueSchema::forTcaConfig($tcaConfig);
                if ($valueSchema !== null) {
                    return $valueSchema;
                }
            }
        }

        return $this->buildPropertySchema($columnDef);
    }

    /**
     * Restricts the type field of a record-type scoped resource to its allowed types.
     *
     * Write schemas always carry the field, because create accepts and checks it
     * even when the column is not writable otherwise. The schema is shared by
     * create and update, so the create-only requirement of a multi-type resource
     * is added by the create operation instead.
     */
    private function withRecordTypeEnum(array $schema, ApiDefinition $config, bool $isWrite): array
    {
        if (!$config->isRecordTypeScoped()) {
            return $schema;
        }

        $field      = $config->recordTypeField;
        $properties = \is_array($schema['properties']) ? $schema['properties'] : [];
        if (!isset($properties[$field]) && !$isWrite) {
            return $schema;
        }

        $properties[$field] = self::recordTypeEnum($properties[$field] ?? ['type' => 'string'], $config->recordTypes);
        $schema['properties'] = $properties;

        return $schema;
    }

    /**
     * The type field restricted to record types. A nullable column is a oneOf with
     * null; the type field always holds a type, so its non-null branch is used.
     *
     * @param array<int, string> $recordTypes
     */
    private static function recordTypeEnum(array $property, array $recordTypes): array
    {
        foreach ($property['oneOf'] ?? [] as $branch) {
            if (($branch['type'] ?? null) !== 'null') {
                $property = $branch;
                break;
            }
        }
        $property['enum'] = ($property['type'] ?? null) === 'integer'
            ? array_map('intval', $recordTypes)
            : array_values($recordTypes);

        return $property;
    }

    private function buildPropertySchema(ColumnDefinition $columnDef): array
    {
        $type = $this->columnTypeToJsonType($columnDef->type ?? '');
        return ['type' => $type];
    }

    private function columnTypeToJsonType(string $type): string
    {
        return match (strtolower($type)) {
            'integer', 'int' => 'integer',
            'boolean', 'bool' => 'boolean',
            'number', 'float', 'double' => 'number',
            default => 'string',
        };
    }

    private function buildHasOneSchema(ColumnDefinition $columnDef, array $referenceSchema): array
    {
        if ($columnDef->embed && $columnDef->resourceType !== null) {
            return ['oneOf' => [['$ref' => '#/components/schemas/' . $columnDef->resourceType . 'Read'], ['type' => 'null']]];
        }
        return ['oneOf' => [$referenceSchema, ['type' => 'null']]];
    }

    private function buildHasManySchema(ColumnDefinition $columnDef, array $referenceSchema): array
    {
        if ($columnDef->embed && $columnDef->resourceType !== null) {
            return ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . $columnDef->resourceType . 'Read']];
        }
        return ['type' => 'array', 'items' => $referenceSchema];
    }

    /**
     * Schema of one related record with the embed depth the serializer applies:
     * a reference once the depth is used up, and for a record embedded with
     * "embed.columns" the related resource's read schema cut down to those
     * columns. Null for polymorphic relations, handled by the existing group schemas.
     *
     * An embedded record is cut short to a reference only when it is already on the
     * embed path (RelationSerializer's $visited, keyed by table and uid), so the
     * reference is allowed only when the related table is on the path: the
     * ancestors for a to-one relation, the ancestors and this record for a to-many
     * one. Categories embedded in an article are always objects; a parent article
     * embedded in an article may be a reference.
     *
     * @param ApiDefinition[] $resources
     * @param list<string>    $ancestorTables
     */
    private function relatedRecordSchema(array $tcaConfig, ColumnDefinition $columnDef, ApiDefinition $config, array $resources, int $remainingDepth, array $ancestorTables, bool $hasOne): ?array
    {
        $depth = $remainingDepth >= 0 ? $remainingDepth : $columnDef->embedDepth();
        if ($depth <= 0) {
            return $this->referenceSchema($tcaConfig, $columnDef, $config, $resources);
        }

        $table = $this->relatedTable($tcaConfig);
        if ($table === null) {
            return null;
        }

        $embedPath  = [...$ancestorTables, $config->table];
        $related    = $this->relatedConfig($table, $columnDef, $config, $resources) ?? RelationSerializer::buildDefaultConfig($table, $columnDef);
        $readSchema = $this->buildReadSchema($related, $resources, $depth - 1, null, $embedPath);
        $properties = $columnDef->embedColumns() === []
            ? $readSchema['properties']
            : array_intersect_key(
                $readSchema['properties'],
                array_flip(['@type', '@id', 'uid', ...$columnDef->embedColumns()]),
            );
        if (!$related->isAddressable()) {
            unset($properties['@id']);
        }

        $embedded = ['type' => 'object', 'properties' => $properties];
        if (!\in_array($table, $hasOne ? $ancestorTables : $embedPath, true)) {
            return $embedded;
        }

        return ['oneOf' => [$embedded, $this->referenceSchema($tcaConfig, $columnDef, $config, $resources)]];
    }

    /**
     * Schema of a related record rendered without embedding, mirroring
     * RelationSerializer::reference(): an IRI when the related resource has an
     * item endpoint, its uid otherwise. Polymorphic groups can hold either, with
     * the uid wrapped in a node naming its type.
     *
     * @param ApiDefinition[] $resources
     */
    private function referenceSchema(array $tcaConfig, ColumnDefinition $columnDef, ApiDefinition $config, array $resources): array
    {
        $iri = ['type' => 'string', 'format' => 'iri-reference'];

        $table = $this->relatedTable($tcaConfig);
        if ($table === null) {
            return ['oneOf' => [$iri, [
                'type'       => 'object',
                'properties' => ['@type' => ['type' => 'string'], 'uid' => ['type' => 'integer']],
                'required'   => ['@type', 'uid'],
            ]]];
        }

        return $this->relatedConfig($table, $columnDef, $config, $resources)?->isAddressable() === true
            ? $iri
            : ['type' => 'integer'];
    }

    /** The single table a relation column points to, or null for a polymorphic group. */
    private function relatedTable(array $tcaConfig): ?string
    {
        $tcaType = $tcaConfig['type'] ?? '';

        if ($tcaType === 'group') {
            $allowed = GeneralUtility::trimExplode(',', (string)($tcaConfig['allowed'] ?? ''), true);
            return \count($allowed) === 1 && $allowed[0] !== '*' ? $allowed[0] : null;
        }

        return $tcaConfig['foreign_table'] ?? ($tcaType === 'category' ? 'sys_category' : null);
    }

    /**
     * Same resolution as ApiRegistry::getRelated(), over the resources this spec documents.
     *
     * @param ApiDefinition[] $resources
     */
    private function relatedConfig(string $table, ColumnDefinition $columnDef, ApiDefinition $config, array $resources): ?ApiDefinition
    {
        if ($table === $config->table && $columnDef->resourceName === null) {
            return $config;
        }

        foreach ($resources as $resource) {
            if ($columnDef->resourceName !== null ? $resource->resourceName === $columnDef->resourceName : $resource->table === $table) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Schema for a type=file TCA column.
     * Single-file (maxitems=1): nullable FileObject. Multi-file: array of FileObjects.
     */
    private function buildFilePropertySchema(array $tcaConfig): array
    {
        $isSingleFile = ($tcaConfig['maxitems'] ?? 0) === 1;
        $fileObjectRef = ['$ref' => '#/components/schemas/FileObject'];

        if ($isSingleFile) {
            return ['oneOf' => [$fileObjectRef, ['type' => 'null']]];
        }

        return ['type' => 'array', 'items' => $fileObjectRef];
    }

    private function isHasOneField(string $tcaType, array $tcaConfig): bool
    {
        return $this->isRelationField($tcaType, $tcaConfig) && RelationCardinality::isHasOne($tcaConfig);
    }

    private function isHasManyField(string $tcaType, array $tcaConfig): bool
    {
        return $this->isRelationField($tcaType, $tcaConfig) && !RelationCardinality::isHasOne($tcaConfig);
    }

    /** A column the serializer renders through RelationSerializer. */
    private function isRelationField(string $tcaType, array $tcaConfig): bool
    {
        return \in_array($tcaType, ['inline', 'category', 'group'], true)
            || ($tcaType === 'select' && isset($tcaConfig['foreign_table']));
    }

    private function hasWriteOperations(ApiDefinition $config): bool
    {
        return $config->hasOperation('create') || $config->hasOperation('update');
    }

    private function buildWriteSchema(ApiDefinition $config): array
    {
        $properties = [];
        $required = [];

        if (!$config->isExplicitMode) {
            $writeType = $config->typeSchemas ? $config->fixedRecordType() : null;
            foreach (array_keys($config->columnMap($writeType)) as $column) {
                $columnDef = $this->resolveDefaultModeColumn($config, $column, $writeType);

                $propSchema = $this->buildPropertySchema($columnDef);
                $propSchema = array_merge($propSchema, $this->mapValidators($columnDef->validators));
                $properties[$column] = $propSchema;

                if ($columnDef->required) {
                    $required[] = $column;
                }
            }
        } else {
            foreach ($config->columns as $column => $columnDef) {
                if (!$columnDef->isWritable()) {
                    continue;
                }
                if (self::isPasswordColumn($config->table, $column)) {
                    continue;
                }

                $propSchema = $this->buildPropertySchema($columnDef);
                $propSchema = array_merge($propSchema, $this->mapValidators($columnDef->validators));
                $properties[$column] = $propSchema;

                if ($columnDef->required) {
                    $required[] = $column;
                }
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Resolve the ColumnDefinition for an exposable TCA column in default mode.
     *
     * When the column is declared in the resource config, return it as-is (its
     * validators were already gap-filled at boot by {@see TcaValidatorDeriver}).
     * When the column is undeclared, synthesise an ephemeral definition carrying
     * the TCA-derived validators and required flag — mirroring the runtime
     * validation performed by FieldValidator without polluting $config->columns.
     */
    private function resolveDefaultModeColumn(ApiDefinition $config, string $column, ?string $recordType = null): ColumnDefinition
    {
        $declared = $config->columns[$column] ?? null;
        if ($declared !== null) {
            if ($recordType !== null) {
                return $config->columnMap($recordType)[$column] ?? $declared;
            }
            return $declared;
        }

        return new ColumnDefinition(
            groups:     null,
            required:   TcaValidatorDeriver::isTcaColumnRequired($config->table, $column, $recordType),
            validators: TcaValidatorDeriver::deriveValidatorsForColumn($config->table, $column, $recordType),
        );
    }

    private function mapValidators(array $validators): array
    {
        $constraints = [];

        foreach ($validators as $validator) {
            switch ($validator['type'] ?? '') {
                case 'maxLength':
                    $constraints['maxLength'] = (int)$validator['max'];
                    break;
                case 'minLength':
                    $constraints['minLength'] = (int)$validator['min'];
                    break;
                case 'regex':
                    $pattern = $this->stripPhpRegexDelimiters((string)($validator['pattern'] ?? ''));
                    if ($pattern !== '') {
                        $constraints['pattern'] = $pattern;
                    }
                    break;
                case 'minValue':
                    $constraints['minimum'] = $validator['min'];
                    break;
                case 'maxValue':
                    $constraints['maximum'] = $validator['max'];
                    break;
                case 'minItems':
                    $constraints['minItems'] = (int)$validator['min'];
                    break;
                case 'maxItems':
                    $constraints['maxItems'] = (int)$validator['max'];
                    break;
            }
        }

        return $constraints;
    }

    private function stripPhpRegexDelimiters(string $phpPattern): string
    {
        if ($phpPattern === '') {
            return '';
        }

        $delimiter = $phpPattern[0];
        $closing = match ($delimiter) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
            default => $delimiter,
        };

        $lastDelimiter = strrpos($phpPattern, $closing);
        if ($lastDelimiter === false || $lastDelimiter === 0) {
            return $phpPattern;
        }

        return substr($phpPattern, 1, $lastDelimiter - 1);
    }

    private function buildCollectionSchema(string $schemaName): array
    {
        return [
            'type' => 'object',
            'properties' => [
                '@context' => ['type' => 'string'],
                '@type' => ['type' => 'string', 'example' => 'hydra:Collection'],
                '@id' => ['type' => 'string'],
                'hydra:totalItems' => ['type' => 'integer'],
                'hydra:member' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/' . $schemaName . 'Read'],
                ],
                'hydra:view' => [
                    'type' => 'object',
                    'properties' => [
                        '@type' => ['type' => 'string'],
                        'hydra:first' => ['type' => 'string'],
                        'hydra:last' => ['type' => 'string'],
                        // null on the first and the last page
                        'hydra:previous' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                        'hydra:next' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
                    ],
                ],
                // Only present when the resource has public filters or orderings.
                'hydra:search' => [
                    'type' => 'object',
                    'properties' => [
                        '@type' => ['type' => 'string', 'example' => 'hydra:IriTemplate'],
                        'hydra:template' => ['type' => 'string'],
                        'hydra:variableRepresentation' => ['type' => 'string', 'example' => 'BasicRepresentation'],
                        'hydra:mapping' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    '@type' => ['type' => 'string', 'example' => 'IriTemplateMapping'],
                                    'variable' => ['type' => 'string'],
                                    'property' => ['type' => 'string'],
                                    'required' => ['type' => 'boolean'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function hasUploadColumns(ApiDefinition $config, string $operation = ''): bool
    {
        foreach ($config->columns as $columnDef) {
            if ($columnDef->upload === null) {
                continue;
            }
            if ($operation === '' || $columnDef->isWritable($operation)) {
                return true;
            }
        }

        return false;
    }

    private function buildMultipartWriteSchema(ApiDefinition $config): array
    {
        $properties = [];
        $required = [];

        if (!$config->isExplicitMode) {
            $writeType = $config->typeSchemas ? $config->fixedRecordType() : null;
            foreach (array_keys($config->columnMap($writeType)) as $column) {
                $columnDef = $this->resolveDefaultModeColumn($config, $column, $writeType);
                $properties[$column] = $this->buildMultipartPropertySchema($columnDef, $config->table, $column);
                if ($columnDef->required) {
                    $required[] = $column;
                }
            }
        } else {
            foreach ($config->columns as $column => $columnDef) {
                if (!$columnDef->isWritable()) {
                    continue;
                }
                if (self::isPasswordColumn($config->table, $column)) {
                    continue;
                }
                $properties[$column] = $this->buildMultipartPropertySchema($columnDef, $config->table, $column);
                if ($columnDef->required) {
                    $required[] = $column;
                }
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private static function isPasswordColumn(string $table, string $column): bool
    {
        return ($GLOBALS['TCA'][$table]['columns'][$column]['config']['type'] ?? '') === 'password';
    }

    private function buildMultipartPropertySchema(ColumnDefinition $columnDef, string $table = '', string $column = ''): array
    {
        if ($columnDef->upload !== null) {
            $schema = ['type' => 'string', 'format' => 'binary'];
            $tcaAllowed = ($table !== '' && $column !== '')
                ? ($GLOBALS['TCA'][$table]['columns'][$column]['config']['allowed'] ?? '')
                : '';
            if (\is_string($tcaAllowed) && $tcaAllowed !== '' && $tcaAllowed !== 'common-image-types') {
                $schema['description'] = 'Allowed extensions: ' . $tcaAllowed;
            }
            if ($columnDef->upload->maxSize !== null) {
                $maxMb = round($columnDef->upload->maxSize / 1_048_576, 1);
                $sizeStr = $maxMb >= 1 ? $maxMb . ' MB' : round($columnDef->upload->maxSize / 1_024, 1) . ' KB';
                $schema['description'] = ($schema['description'] ?? '') !== ''
                    ? ($schema['description'] . '; max ' . $sizeStr)
                    : ('Max size: ' . $sizeStr);
            }

            return $schema;
        }

        $propSchema = $this->buildPropertySchema($columnDef);
        return array_merge($propSchema, $this->mapValidators($columnDef->validators));
    }
}
