<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolved URL context for a single API doc build pass.
 * Constructed once in the dispatcher and passed to all sub-builders.
 */
final readonly class BuildContext
{
    public string $prefix;

    public string $docsBase;

    public function __construct(
        public SiteSettings $settings,
        public string $baseUrl = '',
    ) {
        $this->prefix = rtrim((string)$settings->get('tca_api.apiPrefix', '/_api/'), '/');
        $this->docsBase = $baseUrl . $this->prefix . '/docs.jsonld#';
    }

    public function linkHeader(): string
    {
        return sprintf(
            '<%s%s/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"',
            $this->baseUrl,
            $this->prefix,
        );
    }

    /**
     * @param array<string, ApiDefinition> $resources
     * @return array<string, ApiDefinition>
     */
    public function filterAllowedResources(array $resources): array
    {
        $allowed = GeneralUtility::trimExplode(
            ',',
            (string)$this->settings->get('tca_api.allowedResources', ''),
            true,
        );

        if ($allowed === []) {
            return $resources;
        }

        return array_filter(
            $resources,
            static fn(string $name): bool => \in_array($name, $allowed, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
