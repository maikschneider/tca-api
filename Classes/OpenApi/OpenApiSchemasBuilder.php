<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;

final readonly class OpenApiSchemasBuilder
{
    /** @param array<string, ApiDefinition> $resources */
    public function build(array $resources): array
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

        foreach ($resources as $config) {
            $schemas[$config->resourceType . 'Read'] = $this->buildReadSchema($config);
            if ($this->hasWriteOperations($config)) {
                $schemas[$config->resourceType . 'Write'] = $this->buildWriteSchema($config);
            }
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
                if (self::isPasswordColumn($config->table, $column)) {
                    continue;
                }
                $properties[$column] = $this->buildPropertySchema($columnDef);
            }
        }

        return ['type' => 'object', 'properties' => $properties];
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

    private function hasWriteOperations(ApiDefinition $config): bool
    {
        return $config->hasOperation('create') || $config->hasOperation('update');
    }

    private function buildWriteSchema(ApiDefinition $config): array
    {
        $properties = [];
        $required = [];

        if (!$config->isExplicitMode) {
            foreach (TcaColumnDiscovery::getExposableColumnNames($config->table) as $column) {
                $columnDef = $config->columns[$column] ?? new ColumnDefinition(groups: null);
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
            foreach (TcaColumnDiscovery::getExposableColumnNames($config->table) as $column) {
                $columnDef = $config->columns[$column] ?? new ColumnDefinition(groups: null);
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
