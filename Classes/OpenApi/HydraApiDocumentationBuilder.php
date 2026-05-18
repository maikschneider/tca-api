<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Registry\ApiRegistry;

final readonly class HydraApiDocumentationBuilder
{
    public function __construct(private ApiRegistry $apiRegistry)
    {
    }

    public function build(BuildContext $ctx): array
    {
        $resources = $ctx->filterAllowedResources($this->apiRegistry->getAll());

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
            'expects' => ['@id' => 'hydra:expects', '@type' => '@id'],
            'returns' => ['@id' => 'hydra:returns', '@type' => '@id'],
        ];

        $supportedClasses = [$this->buildEntrypointClass($resources, $ctx->docsBase)];
        foreach ($resources as $config) {
            if ($config->isUserInfo()) {
                continue;
            }
            $supportedClasses[] = $this->buildResourceClass($config);
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
                    'range' => 'hydra:PagedCollection',
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

    private function buildResourceClass(ApiDefinition $config): array
    {
        $operations = [];

        if ($config->hasOperation('show')) {
            $operations[] = [
                '@type' => ['hydra:Operation', 'schema:FindAction'],
                'hydra:method' => 'GET',
                'hydra:title' => sprintf('Retrieves a %s resource.', $config->resourceType),
                'rdfs:label' => sprintf('Retrieves a %s resource.', $config->resourceType),
                'returns' => '#' . $config->resourceType,
                'hydra:expects' => 'owl:Nothing',
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
                'hydra:expects' => 'owl:Nothing',
            ];
        }

        return [
            '@id' => '#' . $config->resourceType,
            '@type' => 'Class',
            'hydra:title' => $config->resourceType,
            'hydra:description' => '',
            'hydra:supportedProperty' => $this->buildSupportedProperties($config),
            'hydra:supportedOperation' => $operations,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function buildSupportedProperties(ApiDefinition $config): array
    {
        $properties = [
            [
                '@type' => 'SupportedProperty',
                'hydra:property' => [
                    '@id' => 'schema:uid',
                    '@type' => 'rdf:Property',
                    'rdfs:label' => 'uid',
                    'domain' => '#' . $config->resourceType,
                    'range' => 'xmls:integer',
                ],
                'hydra:title' => 'uid',
                'hydra:required' => true,
                'hydra:readable' => true,
                'hydra:writeable' => false,
            ],
        ];

        foreach ($config->columns as $name => $column) {
            if ($config->isExplicitMode && !$column->isReadable() && !$column->isWritable()) {
                continue;
            }

            $properties[] = [
                '@type' => 'SupportedProperty',
                'hydra:property' => [
                    '@id' => 'schema:' . $name,
                    '@type' => 'rdf:Property',
                    'rdfs:label' => $name,
                    'domain' => '#' . $config->resourceType,
                    'range' => 'xmls:' . $this->columnTypeToXsdType($column->type ?? ''),
                ],
                'hydra:title' => $name,
                'hydra:required' => $column->required,
                'hydra:readable' => !$config->isExplicitMode || $column->isReadable(),
                'hydra:writeable' => !$config->isExplicitMode || $column->isWritable(),
            ];
        }

        return $properties;
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
