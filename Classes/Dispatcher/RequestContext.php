<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Dispatcher;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolved context for a single API request.
 * Constructed once in the dispatcher and passed to all sub-builders.
 */
final readonly class RequestContext
{
    public string $baseUrl;

    public string $prefix;

    public string $docsBase;

    public string $resource;

    public ?int $uid;

    public ?SiteLanguage $language;

    public function __construct(
        public SiteSettings $settings,
        ServerRequestInterface $request,
        ?SiteLanguage $language = null,
    ) {
        $requestLanguage = $request->getAttribute('language');
        $this->language = $language ?? ($requestLanguage instanceof SiteLanguage ? $requestLanguage : null);

        $uri = $request->getUri();
        $this->baseUrl = $uri->getScheme() . '://' . $uri->getAuthority();
        $this->prefix = rtrim((string)$settings->get('tca_api.apiPrefix', '/_api/'), '/');
        $this->docsBase = $this->baseUrl . $this->prefix . '/docs.jsonld#';

        $requestPrefix = (string)$request->getAttribute('tca_api.request_prefix', $this->prefix);
        $segments = explode('/', trim(substr($uri->getPath(), \strlen($requestPrefix)), '/'));
        $this->resource = $segments[0];
        $this->uid = isset($segments[1]) && $segments[1] !== '' ? (int)$segments[1] : null;
    }

    public function linkHeader(): string
    {
        return sprintf(
            '<%s%s/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"',
            $this->baseUrl,
            $this->prefix,
        );
    }

    /** Without general.enabledSetting a resource is enabled on every site, otherwise only where that setting is true. */
    public function isDefinitionEnabled(ApiDefinition $definition): bool
    {
        return $definition->enabledSetting === null || $this->settings->get($definition->enabledSetting) === true;
    }

    /** Without cache.enabledSetting cache.enabled decides, otherwise the cache is used only where that setting is true. */
    public function isCacheEnabled(ApiDefinition $definition): bool
    {
        $setting = $definition->cache->enabledSetting;

        return $definition->cache->enabled && ($setting === null || $this->settings->get($setting) === true);
    }

    public function isResourceAllowed(string $resource): bool
    {
        $allowed = $this->allowedResourceNames();
        return $allowed === [] || \in_array($resource, $allowed, true);
    }

    /**
     * @param array<string, ApiDefinition> $resources
     * @return array<string, ApiDefinition>
     */
    public function filterAllowedResources(array $resources): array
    {
        $resources = array_filter($resources, $this->isDefinitionEnabled(...));
        $allowed = $this->allowedResourceNames();

        if ($allowed === []) {
            return $resources;
        }

        return array_filter(
            $resources,
            static fn (string $name): bool => \in_array($name, $allowed, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The resources relations may link to, in the form of tca_api.allowedResources (empty = all):
     * that setting, narrowed to the enabled resources when the site disables some of them.
     *
     * @param array<string, ApiDefinition> $resources
     * @return list<string>
     */
    public function servedResourceNames(array $resources): array
    {
        if (array_filter($resources, $this->isDefinitionEnabled(...)) === $resources) {
            return $this->allowedResourceNames();
        }

        return array_map(strval(...), array_keys($this->filterAllowedResources($resources)));
    }

    /** @return list<string> the site's tca_api.allowedResources; empty = all resources */
    public function allowedResourceNames(): array
    {
        return GeneralUtility::trimExplode(
            ',',
            (string)$this->settings->get('tca_api.allowedResources', ''),
            true,
        );
    }
}
