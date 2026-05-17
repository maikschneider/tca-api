<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class HydraEntrypointBuilder
{
    public function __construct(private ApiRegistry $apiRegistry)
    {
    }

    public function build(SiteSettings $settings, string $baseUrl = ''): array
    {
        $prefix = rtrim((string)$settings->get('tca_api.apiPrefix', '/_api'), '/');
        $docsBase = $baseUrl . $prefix . '/docs.jsonld#';
        $resources = $this->filterAllowedResources($this->apiRegistry->getAll(), $settings);

        $context = [
            '@vocab' => 'http://www.w3.org/ns/hydra/core#',
            'hydra' => 'http://www.w3.org/ns/hydra/core#',
            'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
            'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
            'owl' => 'http://www.w3.org/2002/07/owl#',
            'schema' => 'http://schema.org/',
            // Absolute IRI required — relative paths resolve against @vocab, not document base
            'Entrypoint' => $docsBase . 'Entrypoint',
        ];

        $links = [];
        foreach ($resources as $name => $config) {
            if ($config->isUserInfo()) {
                continue;
            }
            $context[$name] = ['@id' => $docsBase . 'Entrypoint/' . $name, '@type' => '@id'];
            $links[$name] = $prefix . '/' . $name;
        }

        return array_merge(
            ['@context' => $context, '@id' => $prefix . '/', '@type' => 'Entrypoint'],
            $links,
        );
    }

    /**
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

    public function buildLinkHeader(SiteSettings $settings, string $baseUrl = ''): string
    {
        $prefix = rtrim((string)$settings->get('tca_api.apiPrefix', '/_api'), '/');
        return sprintf(
            '<%s%s/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"',
            $baseUrl,
            $prefix,
        );
    }
}
