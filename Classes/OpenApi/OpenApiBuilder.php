<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Registry\ApiRegistry;

readonly class OpenApiBuilder
{
    public function __construct(
        private ApiRegistry $apiRegistry,
        private OpenApiPathsBuilder $pathsBuilder,
        private OpenApiSchemasBuilder $schemasBuilder,
    ) {
    }

    public function build(BuildContext $ctx): array
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

        return [
            'openapi' => '3.1.0',
            'info' => $info,
            'paths' => $this->pathsBuilder->build($resources, $ctx),
            'components' => [
                'schemas' => $this->schemasBuilder->build($resources),
            ],
        ];
    }
}
