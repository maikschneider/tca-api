<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class OpenApiBuilder
{
    public function __construct(private ApiRegistry $apiRegistry)
    {
    }

    public function build(SiteSettings $settings): array
    {
        $resources = $this->filterAllowedResources($this->apiRegistry->getAll(), $settings);

        $info = [
            'title' => $settings->get('tca_api.apiSpecTitle'),
            'version' => $settings->get('tca_api.apiSpecVersion'),
            'description' => $settings->get('tca_api.apiSpecDescription'),
        ];

        return [
            'openapi' => '3.1.0',
            'info' => $info,
            'paths' => $this->buildPaths($resources, $settings),
            'components' => [
                'schemas' => $this->buildSchemas($resources),
            ],
        ];
    }

    /**
     * Filter resources by the site-level allowedResources setting.
     *
     * @param array<string, ApiDefinition> $resources
     * @return array<string, ApiDefinition>
     */
    private function filterAllowedResources(array $resources, SiteSettings $settings): array
    {
        $allowed = GeneralUtility::trimExplode(
            ',',
            (string)$settings->get('tca_api.allowedResources', ''),
            true,
        );

        if ($allowed === []) {
            return $resources;
        }

        return array_filter(
            $resources,
            static fn (string $name): bool => \in_array($name, $allowed, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @param array<string, ApiDefinition> $resources */
    private function buildPaths(array $resources, SiteSettings $settings): array
    {
        $paths = [];

        foreach ($resources as $resourceName => $config) {
            $collectionPath = $settings->get('tca_api.apiPrefix') . $resourceName;
            $itemPath = $settings->get('tca_api.apiPrefix') . $resourceName . '/{uid}';

            $collectionItem = [];
            if ($config->hasOperation('list')) {
                $collectionItem['get'] = $this->buildListOperation($resourceName, $config->resourceType, $config);
            }
            if ($config->hasOperation('create')) {
                $collectionItem['post'] = $this->buildCreateOperation($resourceName, $config->resourceType, $config);
            }
            if ($collectionItem !== []) {
                $paths[$collectionPath] = $collectionItem;
            }

            $itemItem = [];
            if ($config->hasOperation('show')) {
                $itemItem['get'] = $this->buildShowOperation($resourceName, $config->resourceType, $config);
            }
            if ($config->hasOperation('update')) {
                $itemItem['put'] = $this->buildUpdateOperation($resourceName, $config->resourceType, $config, partial: false);
                $itemItem['patch'] = $this->buildUpdateOperation($resourceName, $config->resourceType, $config, partial: true);
            }
            if ($config->hasOperation('delete')) {
                $itemItem['delete'] = $this->buildDeleteOperation($resourceName, $config);
            }
            if ($itemItem !== []) {
                $paths[$itemPath] = array_merge(
                    ['parameters' => [['name' => 'uid', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]]],
                    $itemItem,
                );
            }
        }

        return $paths;
    }

    private function accessRoleValue(mixed $role): string
    {
        if ($role instanceof AccessRole) {
            return $role->value;
        }
        // [AccessRole::FE_GROUP, groupIds] tuple
        if (\is_array($role) && $role[0] instanceof AccessRole) {
            return $role[0]->value;
        }
        // [class-string, method] callable
        return 'CALLABLE';
    }

    /**
     * Convert a kebab-case resource name to PascalCase for use in operationId.
     */
    private function toPascalCase(string $resourceName): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $resourceName)));
    }

    private function buildListOperation(string $resourceName, string $resourceType, ApiDefinition $config): array
    {
        return [
            'summary' => 'List ' . $resourceType . ' collection',
            'operationId' => 'list' . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $this->accessRoleValue($config->securityRole('list')),
            'parameters' => $this->buildQueryParams($resourceName, $config),
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                    'content' => [
                        'application/ld+json' => [
                            'schema' => ['$ref' => '#/components/schemas/' . $resourceType . 'Collection'],
                        ],
                    ],
                ],
                '400' => $this->errorResponse('Bad request'),
                '403' => $this->errorResponse('Forbidden'),
                '500' => $this->errorResponse('Internal server error'),
            ],
        ];
    }

    private function buildShowOperation(string $resourceName, string $resourceType, ApiDefinition $config): array
    {
        return [
            'summary' => 'Get single ' . $resourceType,
            'operationId' => 'show' . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $this->accessRoleValue($config->securityRole('show')),
            'parameters' => [
                [
                    'name' => 'fields',
                    'in' => 'query',
                    'description' => 'Sparse fieldset: only return the specified columns (plus @type, @id, uid)',
                    'explode' => true,
                    'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                    'content' => [
                        'application/ld+json' => [
                            'schema' => ['$ref' => '#/components/schemas/' . $resourceType . 'Read'],
                        ],
                    ],
                ],
                '403' => $this->errorResponse('Forbidden'),
                '404' => $this->errorResponse('Not found'),
                '500' => $this->errorResponse('Internal server error'),
            ],
        ];
    }

    private function buildCreateOperation(string $resourceName, string $resourceType, ApiDefinition $config): array
    {
        $content = [
            'application/json' => [
                'schema' => ['$ref' => '#/components/schemas/' . $resourceType . 'Write'],
            ],
        ];
        if ($this->hasUploadColumns($config, 'create')) {
            $content['multipart/form-data'] = [
                'schema' => ['$ref' => '#/components/schemas/' . $resourceType . 'WriteMultipart'],
            ];
        }

        return [
            'summary' => 'Create ' . $resourceType,
            'operationId' => 'create' . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $this->accessRoleValue($config->securityRole('create')),
            'requestBody' => [
                'required' => true,
                'content'  => $content,
            ],
            'responses' => [
                '201' => [
                    'description' => 'Created',
                    'headers' => [
                        'Location' => ['description' => 'URL of newly created resource', 'schema' => ['type' => 'string']],
                    ],
                    'content' => [
                        'application/ld+json' => [
                            'schema' => ['$ref' => '#/components/schemas/' . $resourceType . 'Read'],
                        ],
                    ],
                ],
                '400' => $this->errorResponse('Bad request (e.g. malformed JSON)'),
                '403' => $this->errorResponse('Forbidden'),
                '409' => $this->errorResponse('Conflict'),
                '422' => [
                    'description' => 'Validation error',
                    'content' => [
                        'application/ld+json' => [
                            'schema' => ['$ref' => '#/components/schemas/ValidationError'],
                        ],
                    ],
                ],
                '500' => $this->errorResponse('Internal server error'),
            ],
        ];
    }

    private function buildUpdateOperation(string $resourceName, string $resourceType, ApiDefinition $config, bool $partial): array
    {
        $method  = $partial ? 'Partially update' : 'Update';
        $content = [
            'application/json' => [
                'schema' => ['$ref' => '#/components/schemas/' . $resourceType . 'Write'],
            ],
        ];
        if ($this->hasUploadColumns($config, 'update')) {
            $content['multipart/form-data'] = [
                'schema' => ['$ref' => '#/components/schemas/' . $resourceType . 'WriteMultipart'],
            ];
        }

        return [
            'summary' => $method . ' ' . $resourceType,
            'operationId' => ($partial ? 'patch' : 'update') . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $this->accessRoleValue($config->securityRole('update')),
            'requestBody' => [
                'required' => true,
                'content'  => $content,
            ],
            'responses' => [
                '200' => [
                    'description' => 'Updated',
                    'content' => [
                        'application/ld+json' => [
                            'schema' => ['$ref' => '#/components/schemas/' . $resourceType . 'Read'],
                        ],
                    ],
                ],
                '400' => $this->errorResponse('Bad request (e.g. malformed JSON)'),
                '403' => $this->errorResponse('Forbidden'),
                '404' => $this->errorResponse('Not found'),
                '409' => $this->errorResponse('Conflict'),
                '422' => [
                    'description' => 'Validation error',
                    'content' => [
                        'application/ld+json' => [
                            'schema' => ['$ref' => '#/components/schemas/ValidationError'],
                        ],
                    ],
                ],
                '500' => $this->errorResponse('Internal server error'),
            ],
        ];
    }

    private function buildDeleteOperation(string $resourceName, ApiDefinition $config): array
    {
        return [
            'summary' => 'Delete resource',
            'operationId' => 'delete' . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $this->accessRoleValue($config->securityRole('delete')),
            'responses' => [
                '204' => ['description' => 'Deleted'],
                '403' => $this->errorResponse('Forbidden'),
                '404' => $this->errorResponse('Not found'),
                '405' => $this->errorResponse('Method not allowed'),
                '500' => $this->errorResponse('Internal server error'),
            ],
        ];
    }

    private function buildQueryParams(string $resourceName, ApiDefinition $config): array
    {
        $params = [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            ['name' => 'itemsPerPage', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => $config->itemsPerPage ?? 20]],
        ];

        if ($config->filters !== []) {
            $filterProperties = [];
            foreach ($config->filters as $field => $filterConfig) {
                $options = is_array($filterConfig) ? ($filterConfig[1] ?? []) : [];
                if ($options['private'] ?? false) {
                    continue;
                }
                $filterClass = is_string($filterConfig) ? $filterConfig : ($filterConfig[0] ?? '');
                $shortName   = basename(str_replace('\\', '/', $filterClass)) ?: $filterClass;
                $filterProperties[$field] = ['type' => 'string', 'description' => 'Filter by ' . $field . ' (' . $shortName . ')'];
            }
            $params[] = [
                'name' => 'filters',
                'in' => 'query',
                'style' => 'deepObject',
                'explode' => true,
                'schema' => ['type' => 'object', 'properties' => $filterProperties],
            ];
        }

        if ($config->allowedOrder !== []) {
            $orderProperties = [];
            foreach ($config->allowedOrder as $field) {
                $orderProperties[$field] = ['type' => 'string', 'enum' => ['asc', 'desc']];
            }
            $params[] = [
                'name' => 'order',
                'in' => 'query',
                'style' => 'deepObject',
                'explode' => true,
                'schema' => ['type' => 'object', 'properties' => $orderProperties],
            ];
        }

        $params[] = [
            'name' => 'fields',
            'in' => 'query',
            'description' => 'Sparse fieldset: only return the specified columns (plus @type, @id, uid)',
            'explode' => true,
            'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
        ];

        return $params;
    }

    /** @param array<string, ApiDefinition> $resources */
    private function buildSchemas(array $resources): array
    {
        $schemas = [
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

        foreach ($resources as $resourceName => $config) {
            $schemas[$config->resourceType . 'Read'] = $this->buildReadSchema($config);
            $schemas[$config->resourceType . 'Write'] = $this->buildWriteSchema($config);
            $schemas[$config->resourceType . 'Collection'] = $this->buildCollectionSchema($config->resourceType);
            if ($this->hasUploadColumns($config)) {
                $schemas[$config->resourceType . 'WriteMultipart'] = $this->buildMultipartWriteSchema($config);
            }
        }

        return $schemas;
    }

    private function buildReadSchema(ApiDefinition $config): array
    {
        $properties = [
            '@type' => ['type' => 'string', 'example' => $config->resourceType],
            '@id' => ['type' => 'string'],
            'uid' => ['type' => 'integer'],
        ];

        if (!$config->isExplicitMode) {
            foreach (TcaColumnDiscovery::getExposableColumnNames($config->table) as $column) {
                $columnDef = $config->columns[$column] ?? new ColumnDefinition(groups: null);
                $properties[$column] = $this->buildPropertySchema($columnDef);
            }
        } else {
            foreach ($config->columns as $column => $columnDef) {
                if (!$columnDef->isReadable()) {
                    continue;
                }
                $properties[$column] = $this->buildPropertySchema($columnDef);
            }
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    private function buildWriteSchema(ApiDefinition $config): array
    {
        $properties = [];
        $required   = [];

        if (!$config->isExplicitMode) {
            foreach (TcaColumnDiscovery::getExposableColumnNames($config->table) as $column) {
                $columnDef  = $config->columns[$column] ?? new ColumnDefinition(groups: null);
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

    private function buildCollectionSchema(string $resourceType): array
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
                    'items' => ['$ref' => '#/components/schemas/' . $resourceType . 'Read'],
                ],
                'hydra:view' => [
                    'type' => 'object',
                    'properties' => [
                        '@type' => ['type' => 'string'],
                        'hydra:first' => ['type' => 'string'],
                        'hydra:last' => ['type' => 'string'],
                        'hydra:previous' => ['type' => 'string'],
                        'hydra:next' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build the multipart/form-data write schema for a resource.
     * Identical to the JSON write schema except upload-capable columns are
     * represented as {type: string, format: binary} per OAS 3.1.
     */
    private function buildMultipartWriteSchema(ApiDefinition $config): array
    {
        $properties = [];
        $required   = [];

        if (!$config->isExplicitMode) {
            foreach (TcaColumnDiscovery::getExposableColumnNames($config->table) as $column) {
                $columnDef  = $config->columns[$column] ?? new ColumnDefinition(groups: null);
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

    private function buildMultipartPropertySchema(ColumnDefinition $columnDef, string $table = '', string $column = ''): array
    {
        if ($columnDef->upload !== null) {
            $schema = ['type' => 'string', 'format' => 'binary'];
            // Derive allowed types description from TCA type=file column config.
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

    /**
     * Return true when any column in $config has an upload definition and is
     * writable for the given operation (or any write operation when '' is passed).
     */
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
            }
        }

        return $constraints;
    }

    private function stripPhpRegexDelimiters(string $phpPattern): string
    {
        if ($phpPattern === '') {
            return '';
        }

        // Extract delimiter from first character
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

        // Return only the inner pattern, strip delimiter and any trailing flags
        return substr($phpPattern, 1, $lastDelimiter - 1);
    }

    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/ld+json' => [
                    'schema' => ['$ref' => '#/components/schemas/HydraError'],
                ],
            ],
        ];
    }
}
