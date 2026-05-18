<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\Registry\ApiRegistry;

final readonly class HydraEntrypointBuilder
{
    public function __construct(private ApiRegistry $apiRegistry)
    {
    }

    public function build(RequestContext $ctx): array
    {
        $resources = $ctx->filterAllowedResources($this->apiRegistry->getAll());

        $context = [
            '@vocab' => 'http://www.w3.org/ns/hydra/core#',
            'hydra' => 'http://www.w3.org/ns/hydra/core#',
            'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
            'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
            'owl' => 'http://www.w3.org/2002/07/owl#',
            'schema' => 'http://schema.org/',
            // Absolute IRI required — relative paths resolve against @vocab, not document base
            'Entrypoint' => $ctx->docsBase . 'Entrypoint',
        ];

        $links = [];
        foreach ($resources as $name => $config) {
            if ($config->isUserInfo()) {
                continue;
            }
            $context[$name] = ['@id' => $ctx->docsBase . 'Entrypoint/' . $name, '@type' => '@id'];
            $links[$name] = $ctx->prefix . '/' . $name;
        }

        return array_merge(
            ['@context' => $context, '@id' => $ctx->prefix . '/', '@type' => 'Entrypoint'],
            $links,
        );
    }
}
