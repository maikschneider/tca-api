<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\Processing;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Generates frontend URLs for a record from a typed RouteDefinition.
 *
 * Delegates URL construction to the TYPO3 site router so any configured
 * routeEnhancer (e.g. an Extbase News plugin) transparently applies — the
 * processor never builds path segments itself.
 *
 * Configuration lives under the column's `route` key; see RouteDefinition for
 * the full grammar. Common shapes:
 *
 *   // Extbase route enhancer (News detail → /news/{slug})
 *   'route' => [
 *       'pid'        => '{$tca_api.news.detailPid}',
 *       'extension'  => 'News',
 *       'plugin'     => 'Pi1',
 *       'controller' => 'News',
 *       'action'     => 'detail',
 *       'arguments'  => ['news' => '{uid}'],
 *   ]
 *
 *   // Plain page link
 *   'route' => ['pid' => 42]
 *
 *   // Plain page link with extra query params
 *   'route' => ['pid' => 42, 'parameters' => ['ref' => '{uid}']]
 */
final class RouteEnhancerProcessor implements ColumnProcessorInterface
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly PlaceholderResolver $placeholderResolver,
    ) {
    }

    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        $route = $config->route;
        if ($route === null) {
            return null;
        }

        $rawRow   = \is_array($context['rawRow'] ?? null) ? $context['rawRow'] : [];
        $request  = $this->currentRequest();
        $settings = $this->currentSiteSettings($request);

        // 1. Resolve the target page id.
        $pageId = $this->placeholderResolver->resolve($route->pid, $rawRow, $settings);
        if (\is_string($pageId) && ctype_digit($pageId)) {
            $pageId = (int)$pageId;
        }
        if (!\is_int($pageId) || $pageId < 1) {
            return null;
        }

        // 2. Resolve placeholders in arguments/parameters.
        $arguments  = $this->placeholderResolver->resolve($route->arguments, $rawRow, $settings);
        $parameters = $this->placeholderResolver->resolve($route->parameters, $rawRow, $settings);
        if ($arguments === null || $parameters === null) {
            return null;
        }

        // 3. Compose query parameters. Extbase wraps under tx_<ext>_<plugin>.
        $queryParams = \is_array($parameters) ? $parameters : [];
        if ($route->isExtbase()) {
            $extbaseArgs = \is_array($arguments) ? $arguments : [];
            if ($route->controller !== null) {
                $extbaseArgs['controller'] = $route->controller;
            }
            if ($route->action !== null) {
                $extbaseArgs['action'] = $route->action;
            }
            $queryParams[$route->extbaseNamespace()] = $extbaseArgs;
        }

        // 4. Tell the router which language base to use, if known.
        $language = $this->currentLanguage($request);
        if ($language instanceof SiteLanguage) {
            $queryParams['_language'] = $language;
        }

        // 5. Hand off to the site router. RouteEnhancers attached to the page
        //    handle the speaking-URL transformation themselves.
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $uri  = $site->getRouter()->generateUri(
                $pageId,
                $queryParams,
                $route->fragment ?? '',
                $route->absolute ? RouterInterface::ABSOLUTE_URL : RouterInterface::ABSOLUTE_PATH,
            );

            return (string)$uri;
        } catch (\Exception) {
            return null;
        }
    }

    private function currentRequest(): ?ServerRequestInterface
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        return $request instanceof ServerRequestInterface ? $request : null;
    }

    private function currentLanguage(?ServerRequestInterface $request): ?SiteLanguage
    {
        if ($request === null) {
            return null;
        }
        // Prefer the API dispatcher's resolved language (which honors X-Locale
        // overrides), then fall back to the standard PSR-7 attribute set by
        // the TYPO3 frontend SiteResolver.
        foreach (['tca_api.language', 'language'] as $attr) {
            $value = $request->getAttribute($attr);
            if ($value instanceof SiteLanguage) {
                return $value;
            }
        }
        return null;
    }

    private function currentSiteSettings(?ServerRequestInterface $request): ?SiteSettings
    {
        if ($request === null) {
            return null;
        }
        $site = $request->getAttribute('site');
        return $site instanceof Site ? $site->getSettings() : null;
    }
}
