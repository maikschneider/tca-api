<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

readonly class OpenApiBuilder
{
    public function __construct(private SiteSettings $settings)
    {
    }

    public function build(): array
    {
        $resources = ApiRegistry::getAll();

        $info = [
            'title' => $this->settings->get('tca_api.apiSpecTitle'),
            'version' => $this->settings->get('tca_api.apiSpecVersion'),
            'description' => $this->settings->get('tca_api.apiSpecDescription'),
        ];

        $spec = [
            'openapi' => '3.1.0',
            'info' => $info,
            'paths' => $this->buildPaths($resources),
            'components' => [
                'schemas' => $this->buildSchemas($resources),
            ],
        ];

        return $spec;
    }

    /** @param array<string, ApiDefinition> $resources */
    private function buildPaths(array $resources): array
    {
        $paths = [];

        foreach ($resources as $resourceName => $config) {
            $collectionPath = $this->settings->get('tca_api.apiPrefix') . $resourceName;
            $itemPath = $this->settings->get('tca_api.apiPrefix') . $resourceName . '/{uid}';

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
        return $role instanceof AccessRole ? $role->value : 'PUBLIC';
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
                '403' => ['description' => 'Forbidden'],
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
                    'style' => 'deepObject',
                    'explode' => true,
                    'schema' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
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
                '403' => ['description' => 'Forbidden'],
                '404' => ['description' => 'Not found'],
            ],
        ];
    }

    private function buildCreateOperation(string $resourceName, string $resourceType, ApiDefinition $config): array
    {
        return [
            'summary' => 'Create ' . $resourceType,
            'operationId' => 'create' . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $this->accessRoleValue($config->securityRole('create')),
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/' . $resourceType . 'Write'],
                    ],
                ],
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
                '403' => ['description' => 'Forbidden'],
                '422' => [
                    'description' => 'Validation error',
                    'content' => [
                        'application/ld+json' => [
                            'schema' => ['$ref' => '#/components/schemas/ValidationError'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function buildUpdateOperation(string $resourceName, string $resourceType, ApiDefinition $config, bool $partial): array
    {
        $method = $partial ? 'Partially update' : 'Update';

        return [
            'summary' => $method . ' ' . $resourceType,
            'operationId' => ($partial ? 'patch' : 'update') . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $this->accessRoleValue($config->securityRole('update')),
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/' . $resourceType . 'Write'],
                    ],
                ],
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
                '403' => ['description' => 'Forbidden'],
                '404' => ['description' => 'Not found'],
                '422' => [
                    'description' => 'Validation error',
                    'content' => [
                        'application/ld+json' => [
                            'schema' => ['$ref' => '#/components/schemas/ValidationError'],
                        ],
                    ],
                ],
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
                '403' => ['description' => 'Forbidden'],
                '404' => ['description' => 'Not found'],
            ],
        ];
    }

    private function buildQueryParams(string $resourceName, ApiDefinition $config): array
    {
        $params = [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            ['name' => 'itemsPerPage', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => $config->itemsPerPage]],
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
            'style' => 'deepObject',
            'explode' => true,
            'schema' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ];

        return $params;
    }

    /** @param array<string, ApiDefinition> $resources */
    private function buildSchemas(array $resources): array
    {
        $schemas = [
            'ValidationError' => [
                'type' => 'object',
                'properties' => [
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
}
