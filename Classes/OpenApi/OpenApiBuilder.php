<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\Registry\ApiRegistry;

readonly class OpenApiBuilder
{
    public function __construct(
        private ApiRegistry $apiRegistry,
        private OpenApiPathsBuilder $pathsBuilder,
        private OpenApiSchemasBuilder $schemasBuilder,
    ) {
    }

    public function build(RequestContext $ctx): array
    {
        $resources = $ctx->filterAllowedResources($this->apiRegistry->getAll());

        $description = (string)$ctx->settings->get('tca_api.apiSpecDescription', '');
        $info = [
            'title'   => (string)$ctx->settings->get('tca_api.apiSpecTitle'),
            'version' => (string)$ctx->settings->get('tca_api.apiSpecVersion'),
        ];
        if ($description !== '') {
            $info['description'] = $description;
        }

        $document = [
            'openapi' => '3.1.0',
            'info' => $info,
            'paths' => $this->pathsBuilder->build($resources, $ctx),
            'components' => [
                'schemas' => $this->schemasBuilder->build($resources),
            ],
        ];

        $tags = $this->buildTags($resources);
        if ($tags !== []) {
            $document['tags'] = $tags;
        }

        return $document;
    }

    /**
     * Builds the top-level OpenAPI tags list from the resource groups.
     *
     * One entry per distinct tag, in resource-registration order (which drives the
     * section order in Swagger UI). A configured group description is attached to its
     * tag; the first description wins if the same tag is declared more than once.
     *
     * @param array<string, \MaikSchneider\TcaApi\Configuration\ApiDefinition> $resources
     * @return list<array{name: string, description?: string}>
     */
    private function buildTags(array $resources): array
    {
        $tags = [];
        foreach ($resources as $config) {
            $name = $config->tagName();
            if (!isset($tags[$name])) {
                $tags[$name] = ['name' => $name];
            }
            if ($config->groupDescription !== null && !isset($tags[$name]['description'])) {
                $tags[$name]['description'] = $config->groupDescription;
            }
        }

        return array_values($tags);
    }
}
