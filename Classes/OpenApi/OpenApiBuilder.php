<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class OpenApiBuilder
{
    public function __construct(
        private ApiRegistry $apiRegistry,
        private OpenApiPathsBuilder $pathsBuilder,
        private OpenApiSchemasBuilder $schemasBuilder,
    ) {
    }

    public function build(SiteSettings $settings): array
    {
        $resources = $this->filterAllowedResources($this->apiRegistry->getAll(), $settings);

        $description = (string)$settings->get('tca_api.apiSpecDescription', '');
        $info = [
            'title'   => (string)$settings->get('tca_api.apiSpecTitle'),
            'version' => (string)$settings->get('tca_api.apiSpecVersion'),
        ];
        if ($description !== '') {
            $info['description'] = $description;
        }

        return [
            'openapi' => '3.1.0',
            'info' => $info,
            'paths' => $this->pathsBuilder->build($resources, $settings),
            'components' => [
                'schemas' => $this->schemasBuilder->build($resources),
            ],
        ];
    }

    /**
     * Filter resources by the site-level allowedResources setting.
     *
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
}
