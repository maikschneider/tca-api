<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;

final class HydraApiDocumentationBuilder
{
    public function __construct(private readonly ApiRegistry $apiRegistry)
    {
    }

    public function build(RequestContext $ctx): array
    {
        $resources = $ctx->filterAllowedResources($this->apiRegistry->getAll());
        $allowedTypeMap = $this->buildAllowedTypeMap($resources);

        $context = [
            '@vocab' => 'http://www.w3.org/ns/hydra/core#',
            'hydra' => 'http://www.w3.org/ns/hydra/core#',
            'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
            'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
            'owl' => 'http://www.w3.org/2002/07/owl#',
            'schema' => 'http://schema.org/',
            'xsd' => 'http://www.w3.org/2001/XMLSchema#',
            'domain' => ['@id' => 'rdfs:domain', '@type' => '@id'],
            'range' => ['@id' => 'rdfs:range', '@type' => '@id'],
            'subClassOf' => ['@id' => 'rdfs:subClassOf', '@type' => '@id'],
            'expects' => ['@id' => 'expects', '@type' => '@id'],
            'returns' => ['@id' => 'hydra:returns', '@type' => '@id'],
        ];

        $supportedClasses = [$this->buildEntrypointClass($resources, $ctx->docsBase)];
        foreach ($resources as $config) {
            if ($config->isUserInfo()) {
                continue;
            }
            $supportedClasses[] = $this->buildResourceClass($config, $allowedTypeMap);
        }

        $title = (string)$ctx->settings->get('tca_api.apiSpecTitle', 'TCA API');
        $description = (string)$ctx->settings->get('tca_api.apiSpecDescription', '');

        $doc = [
            '@context' => $context,
            '@id' => $ctx->prefix . '/docs.jsonld',
            '@type' => 'ApiDocumentation',
            'hydra:title' => $title,
            'hydra:entrypoint' => $ctx->prefix . '/',
            'hydra:supportedClass' => $supportedClasses,
        ];

        if ($description !== '') {
            $doc['hydra:description'] = $description;
        }

        return $doc;
    }

    /** @param array<string, ApiDefinition> $resources */
    private function buildEntrypointClass(array $resources, string $docsBase): array
    {
        $supportedProperties = [];

        foreach ($resources as $name => $config) {
            if ($config->isUserInfo()) {
                continue;
            }

            $operations = [];

            if ($config->hasOperation('list')) {
                $operations[] = [
                    '@type' => ['hydra:Operation', 'schema:FindAction'],
                    'hydra:method' => 'GET',
                    'hydra:title' => sprintf('Retrieves the collection of %s resources.', $config->resourceType),
                    'rdfs:label' => sprintf('Retrieves the collection of %s resources.', $config->resourceType),
                    'returns' => '#' . $config->resourceType,
                    'hydra:expects' => 'owl:Nothing',
                ];
            }

            if ($config->hasOperation('create')) {
                $operations[] = [
                    '@type' => ['hydra:Operation', 'schema:CreateAction'],
                    'hydra:method' => 'POST',
                    'hydra:title' => sprintf('Creates a %s resource.', $config->resourceType),
                    'rdfs:label' => sprintf('Creates a %s resource.', $config->resourceType),
                    'returns' => '#' . $config->resourceType,
                    'expects' => '#' . $config->resourceType,
                ];
            }

            if ($operations === []) {
                continue;
            }

            $supportedProperties[] = [
                '@type' => 'SupportedProperty',
                'hydra:property' => [
                    '@id' => $docsBase . 'Entrypoint/' . $name,
                    '@type' => 'Link',
                    'hydra:title' => $name,
                    'domain' => '#Entrypoint',
                    'range' => 'hydra:Collection',
                    'hydra:supportedOperation' => $operations,
                ],
                'hydra:title' => $name,
                'hydra:readable' => true,
                'hydra:writeable' => false,
            ];
        }

        return [
            '@id' => '#Entrypoint',
            '@type' => 'Class',
            'hydra:title' => 'Entrypoint',
            'hydra:supportedProperty' => $supportedProperties,
            'hydra:supportedOperation' => [
                '@type' => 'hydra:Operation',
                'hydra:method' => 'GET',
                'rdfs:label' => 'The API entrypoint.',
                'returns' => '#Entrypoint',
            ],
        ];
    }

    /** @param array<string, string> $allowedTypeMap */
    private function buildResourceClass(ApiDefinition $config, array $allowedTypeMap): array
    {
        $operations = [];

        if ($config->hasOperation('show')) {
            $operations[] = [
                '@type' => ['hydra:Operation', 'schema:FindAction'],
                'hydra:method' => 'GET',
                'hydra:title' => sprintf('Retrieves a %s resource.', $config->resourceType),
                'rdfs:label' => sprintf('Retrieves a %s resource.', $config->resourceType),
                'returns' => '#' . $config->resourceType,
                'expects' => 'owl:Nothing',
            ];
        }

        if ($config->hasOperation('update')) {
            $operations[] = [
                '@type' => ['hydra:Operation', 'schema:ReplaceAction'],
                'hydra:method' => 'PUT',
                'hydra:title' => sprintf('Replaces the %s resource.', $config->resourceType),
                'rdfs:label' => sprintf('Replaces the %s resource.', $config->resourceType),
                'returns' => '#' . $config->resourceType,
                'expects' => '#' . $config->resourceType,
            ];
        }

        if ($config->hasOperation('delete')) {
            $operations[] = [
                '@type' => ['hydra:Operation', 'schema:DeleteAction'],
                'hydra:method' => 'DELETE',
                'hydra:title' => sprintf('Deletes the %s resource.', $config->resourceType),
                'rdfs:label' => sprintf('Deletes the %s resource.', $config->resourceType),
                'returns' => 'owl:Nothing',
                'expects' => 'owl:Nothing',
            ];
        }

        return [
            '@id' => '#' . $config->resourceType,
            '@type' => 'Class',
            'hydra:title' => $config->resourceType,
            'hydra:description' => '',
            'hydra:supportedProperty' => $this->buildSupportedProperties($config, $allowedTypeMap),
            'hydra:supportedOperation' => $operations,
        ];
    }

    /**
     * @param array<string, string> $allowedTypeMap
     * @return array<int, array<string, mixed>>
     */
    private function buildSupportedProperties(ApiDefinition $config, array $allowedTypeMap): array
    {
        $properties = [
            [
                '@type' => 'SupportedProperty',
                'hydra:property' => [
                    '@id' => 'schema:uid',
                    '@type' => 'rdf:Property',
                    'rdfs:label' => 'uid',
                    'domain' => '#' . $config->resourceType,
                    'range' => 'xsd:integer',
                ],
                'hydra:title' => 'uid',
                'hydra:required' => true,
                'hydra:readable' => true,
                'hydra:writeable' => false,
            ],
        ];

        $columnMap = $config->isExplicitMode
            ? $config->columns
            : array_combine(
                $cols = TcaColumnDiscovery::getExposableColumnNames($config->table),
                array_map(
                    static fn (string $col): ColumnDefinition => $config->columns[$col] ?? new ColumnDefinition(groups: null),
                    $cols,
                ),
            );

        $tcaLabelColumn = $GLOBALS['TCA'][$config->table]['ctrl']['label'] ?? null;

        foreach ($columnMap as $name => $column) {
            if ($config->isExplicitMode && !$column->isReadable() && !$column->isWritable()) {
                continue;
            }

            // Use schema:name for the TCA label column so api-doc-parser's
            // getFieldNameFromSchema() resolves the human-readable display field.
            $schemaId = ($name === $tcaLabelColumn) ? 'schema:name' : 'schema:' . $name;

            $relatedType = $this->findRelatedResourceType($config->table, $name, $column, $allowedTypeMap);

            if ($relatedType !== null) {
                $hydraProperty = [
                    '@id' => $schemaId,
                    '@type' => ['hydra:Link'],
                    'rdfs:label' => $name,
                    'domain' => '#' . $config->resourceType,
                    'range' => '#' . $relatedType,
                ];

                if ($this->isSingleRelation($config->table, $name)) {
                    $hydraProperty['owl:maxCardinality'] = 1;
                }
            } else {
                $hydraProperty = [
                    '@id' => $schemaId,
                    '@type' => 'rdf:Property',
                    'rdfs:label' => $name,
                    'domain' => '#' . $config->resourceType,
                    'range' => 'xsd:' . $this->columnTypeToXsdType($column->type ?? ''),
                ];
            }

            $properties[] = [
                '@type' => 'SupportedProperty',
                'hydra:property' => $hydraProperty,
                'hydra:title' => $name,
                'hydra:required' => $column->required,
                'hydra:readable' => !$config->isExplicitMode || $column->isReadable(),
                'hydra:writeable' => !$config->isExplicitMode || $column->isWritable(),
            ];
        }

        return $properties;
    }

    /** @param array<string, string> $allowedTypeMap */
    private function findRelatedResourceType(string $table, string $column, ColumnDefinition $columnDef, array $allowedTypeMap): ?string
    {
        // Explicit resourceName on the column takes priority — avoids ambiguity when
        // multiple resources share the same foreign table.
        if ($columnDef->resourceName !== null) {
            $resource = $this->apiRegistry->get($columnDef->resourceName);
            if ($resource !== null && \in_array($resource->resourceType, $allowedTypeMap, true)) {
                return $resource->resourceType;
            }
            return null;
        }

        $foreignTable = $GLOBALS['TCA'][$table]['columns'][$column]['config']['foreign_table'] ?? null;
        if ($foreignTable === null) {
            return null;
        }

        return $allowedTypeMap[$foreignTable] ?? null;
    }

    private function isSingleRelation(string $table, string $column): bool
    {
        $tcaConfig = $GLOBALS['TCA'][$table]['columns'][$column]['config'] ?? [];

        return ($tcaConfig['maxitems'] ?? null) === 1
            || ($tcaConfig['renderType'] ?? '') === 'selectSingle';
    }

    /**
     * @param array<string, ApiDefinition> $resources Already-filtered allowed resources.
     * @return array<string, string> Maps foreign-table → resourceType for O(1) relation lookup.
     */
    private function buildAllowedTypeMap(array $resources): array
    {
        $map = [];
        foreach ($resources as $definition) {
            $map[$definition->table] = $definition->resourceType;
        }
        return $map;
    }

    private function columnTypeToXsdType(string $type): string
    {
        return match (strtolower($type)) {
            'integer', 'int' => 'integer',
            'boolean', 'bool' => 'boolean',
            'number', 'float', 'double' => 'decimal',
            default => 'string',
        };
    }
}
