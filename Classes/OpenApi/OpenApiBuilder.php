<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

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

    private function buildPaths(array $resources): array
    {
        $paths = [];

        foreach ($resources as $resourceName => $config) {
            $operations = $config['general']['operations'] ?? [];
            $collectionPath = $this->settings->get('tca_api.apiPrefix') . $resourceName;
            $itemPath = $this->settings->get('tca_api.apiPrefix') . $resourceName . '/{uid}';
            $resourceType = $config['general']['resourceType'] ?? $resourceName;

            $collectionItem = [];
            if (\in_array('list', $operations, true)) {
                $collectionItem['get'] = $this->buildListOperation($resourceName, $resourceType, $config);
            }
            if (\in_array('create', $operations, true)) {
                $collectionItem['post'] = $this->buildCreateOperation($resourceName, $resourceType, $config);
            }
            if ($collectionItem !== []) {
                $paths[$collectionPath] = $collectionItem;
            }

            $itemItem = [];
            if (\in_array('show', $operations, true)) {
                $itemItem['get'] = $this->buildShowOperation($resourceName, $resourceType, $config);
            }
            if (\in_array('update', $operations, true)) {
                $itemItem['put'] = $this->buildUpdateOperation($resourceName, $resourceType, $config, partial: false);
                $itemItem['patch'] = $this->buildUpdateOperation($resourceName, $resourceType, $config, partial: true);
            }
            if (\in_array('delete', $operations, true)) {
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

    private function buildListOperation(string $resourceName, string $resourceType, array $config): array
    {
        $accessRole = $this->accessRoleValue($config['security']['list'] ?? null);

        return [
            'summary' => 'List ' . $resourceType . ' collection',
            'operationId' => 'list' . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $accessRole,
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

    private function buildShowOperation(string $resourceName, string $resourceType, array $config): array
    {
        $accessRole = $this->accessRoleValue($config['security']['show'] ?? null);

        return [
            'summary' => 'Get single ' . $resourceType,
            'operationId' => 'show' . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $accessRole,
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

    private function buildCreateOperation(string $resourceName, string $resourceType, array $config): array
    {
        $accessRole = $this->accessRoleValue($config['security']['create'] ?? null);
        if (($config['general']['type'] ?? '') === 'fileUpload') {
            return $this->buildFileUploadCreateOperation($resourceName, $resourceType, $accessRole);
        }

        return [
            'summary' => 'Create ' . $resourceType,
            'operationId' => 'create' . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $accessRole,
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

    private function buildFileUploadCreateOperation(string $resourceName, string $resourceType, string $accessRole): array
    {
        return [
            'summary' => 'Upload file',
            'operationId' => 'upload' . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $accessRole,
            'requestBody' => [
                'required' => true,
                'content' => [
                    'multipart/form-data' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['file'],
                            'properties' => [
                                'file' => [
                                    'type' => 'string',
                                    'format' => 'binary',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '201' => [
                    'description' => 'Uploaded',
                    'headers' => [
                        'Location' => ['description' => 'URL of uploaded file resource', 'schema' => ['type' => 'string']],
                    ],
                    'content' => [
                        'application/ld+json' => [
                            'schema' => ['$ref' => '#/components/schemas/' . $resourceType . 'Read'],
                        ],
                    ],
                ],
                '400' => ['description' => 'Invalid upload request'],
                '403' => ['description' => 'Forbidden'],
                '500' => ['description' => 'Upload failed'],
            ],
        ];
    }

    private function buildUpdateOperation(string $resourceName, string $resourceType, array $config, bool $partial): array
    {
        $accessRole = $this->accessRoleValue($config['security']['update'] ?? null);
        $method = $partial ? 'Partially update' : 'Update';

        return [
            'summary' => $method . ' ' . $resourceType,
            'operationId' => ($partial ? 'patch' : 'update') . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $accessRole,
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

    private function buildDeleteOperation(string $resourceName, array $config): array
    {
        $accessRole = $this->accessRoleValue($config['security']['delete'] ?? null);

        return [
            'summary' => 'Delete resource',
            'operationId' => 'delete' . $this->toPascalCase($resourceName),
            'x-typo3-access-role' => $accessRole,
            'responses' => [
                '204' => ['description' => 'Deleted'],
                '403' => ['description' => 'Forbidden'],
                '404' => ['description' => 'Not found'],
            ],
        ];
    }

    private function buildQueryParams(string $resourceName, array $config): array
    {
        $params = [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            ['name' => 'itemsPerPage', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => $config['general']['itemsPerPage'] ?? 20]],
        ];

        $filterFields = $config['filters'] ?? [];
        if ($filterFields !== []) {
            $filterProperties = [];
            foreach ($filterFields as $field => $filterConfig) {
                $filterProperties[$field] = ['type' => 'string', 'description' => 'Filter by ' . $field . ' (strategy: ' . ($filterConfig['strategy'] ?? 'exact') . ')'];
            }
            $params[] = [
                'name' => 'filters',
                'in' => 'query',
                'style' => 'deepObject',
                'explode' => true,
                'schema' => ['type' => 'object', 'properties' => $filterProperties],
            ];
        }

        $allowedOrder = $config['order']['allowed'] ?? [];
        if ($allowedOrder !== []) {
            $orderProperties = [];
            foreach ($allowedOrder as $field) {
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
            $resourceType = $config['general']['resourceType'] ?? $resourceName;

            $schemas[$resourceType . 'Read'] = $this->buildReadSchema($resourceType, $config);
            $schemas[$resourceType . 'Write'] = $this->buildWriteSchema($config);
            $schemas[$resourceType . 'Collection'] = $this->buildCollectionSchema($resourceType);
        }

        return $schemas;
    }

    private function buildReadSchema(string $resourceType, array $config): array
    {
        $properties = [
            '@type' => ['type' => 'string', 'example' => $resourceType],
            '@id' => ['type' => 'string'],
            'uid' => ['type' => 'integer'],
        ];

        $table      = $config['general']['table'];
        $isExplicit = TcaColumnDiscovery::isExplicitMode($config);

        if (!$isExplicit) {
            foreach (TcaColumnDiscovery::getExposableColumnNames($table) as $column) {
                $columnConfig = ($config['columns'] ?? [])[$column] ?? [];
                $properties[$column] = $this->buildPropertySchema($columnConfig);
            }
        } else {
            foreach ($config['columns'] ?? [] as $column => $columnConfig) {
                if (!TcaColumnDiscovery::isColumnReadable($columnConfig)) {
                    continue;
                }
                $properties[$column] = $this->buildPropertySchema($columnConfig);
            }
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    private function buildWriteSchema(array $config): array
    {
        $properties = [];
        $required   = [];
        $table      = $config['general']['table'];
        $isExplicit = TcaColumnDiscovery::isExplicitMode($config);

        if (!$isExplicit) {
            foreach (TcaColumnDiscovery::getExposableColumnNames($table) as $column) {
                $columnConfig = $config['columns'][$column] ?? [];
                $propSchema   = $this->buildPropertySchema($columnConfig);
                $propSchema   = array_merge($propSchema, $this->mapValidators($columnConfig['validators'] ?? []));
                $properties[$column] = $propSchema;

                if ($columnConfig['required'] ?? false) {
                    $required[] = $column;
                }
            }
        } else {
            foreach ($config['columns'] ?? [] as $column => $columnConfig) {
                if (!TcaColumnDiscovery::isColumnWritable($columnConfig)) {
                    continue;
                }

                $propSchema = $this->buildPropertySchema($columnConfig);
                $propSchema = array_merge($propSchema, $this->mapValidators($columnConfig['validators'] ?? []));
                $properties[$column] = $propSchema;

                if ($columnConfig['required'] ?? false) {
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

    private function buildPropertySchema(array $columnConfig): array
    {
        $type = $this->columnTypeToJsonType($columnConfig['type'] ?? '');
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
