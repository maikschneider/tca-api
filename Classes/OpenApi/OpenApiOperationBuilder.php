<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;

final readonly class OpenApiOperationBuilder
{
    public function buildListOperation(string $resourceName, string $resourceType, ApiDefinition $config): array
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

    public function buildShowOperation(string $resourceName, string $resourceType, ApiDefinition $config): array
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

    public function buildCreateOperation(string $resourceName, string $resourceType, ApiDefinition $config): array
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

    public function buildUpdateOperation(string $resourceName, string $resourceType, ApiDefinition $config, bool $partial): array
    {
        $method = $partial ? 'Partially update' : 'Update';
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

    public function buildDeleteOperation(string $resourceName, ApiDefinition $config): array
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

    private function accessRoleValue(mixed $role): string
    {
        if ($role instanceof AccessRole) {
            return $role->value;
        }
        if (\is_array($role) && $role[0] instanceof AccessRole) {
            return $role[0]->value;
        }

        return 'CALLABLE';
    }

    private function toPascalCase(string $resourceName): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $resourceName)));
    }

    private function buildQueryParams(string $resourceName, ApiDefinition $config): array
    {
        $params = [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            ['name' => 'itemsPerPage', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => $config->itemsPerPage ?? 20]],
        ];

        foreach ($config->filters as $field => $filterConfig) {
            if ($filterConfig->isPrivate) {
                continue;
            }
            $shortName = basename(str_replace('\\', '/', $filterConfig->filterClass)) ?: $filterConfig->filterClass;
            $params[] = [
                'name' => $field,
                'in' => 'query',
                'required' => false,
                'description' => 'Filter by ' . $field . ' (' . $shortName . ')',
                'schema' => ['type' => 'string'],
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
