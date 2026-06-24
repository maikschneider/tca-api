<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Middleware;

use MaikSchneider\TcaApi\Dispatcher\RequestDispatcher;
use MaikSchneider\TcaApi\Http\MultipartRequestParser;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class TcaApiMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestDispatcher $dispatcher,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly MultipartRequestParser $multipartRequestParser,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        $settings = $site instanceof Site ? $site->getSettings() : null;
        $requestLanguage = $request->getAttribute('language');
        $requestLanguage = $requestLanguage instanceof SiteLanguage ? $requestLanguage : null;

        if ($settings === null || !$settings->has('tca_api.apiPrefix')) {
            return $handler->handle($request);
        }

        if (!$settings->get('tca_api.enabled', true)) {
            return $handler->handle($request);
        }

        $apiPrefix = rtrim('/' . ltrim((string)$settings->get('tca_api.apiPrefix'), '/'), '/');
        $matchedRequestPrefix = $this->resolveMatchedRequestPrefix($request->getUri()->getPath(), $apiPrefix, $requestLanguage);

        if ($matchedRequestPrefix === null) {
            return $handler->handle($request);
        }

        $corsEnabled = (bool)$settings->get('tca_api.corsEnabled', false);

        $resolvedLanguage = $requestLanguage;
        $override = trim($request->getHeaderLine('X-Locale'));
        if ($override !== '') {
            $resolvedLanguage = $this->resolveLanguageOverride($site, $override);
            if ($resolvedLanguage === null) {
                $invalid = $this->buildInvalidLanguageResponse($site, $override);
                if ($corsEnabled) {
                    $invalid = $this->addCorsHeaders($invalid, $request, $settings);
                }
                return $this->withLocaleHeaders($invalid, $requestLanguage);
            }
        }

        if ($resolvedLanguage !== null) {
            GeneralUtility::makeInstance(Context::class)->setAspect(
                'language',
                LanguageAspectFactory::createFromSiteLanguage($resolvedLanguage),
            );
            $request = $request->withAttribute('language', $resolvedLanguage);
        }

        if ($corsEnabled && strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->withLocaleHeaders($this->buildPreflightResponse($request, $settings), $resolvedLanguage);
        }

        $request = $request
            ->withAttribute('tca_api.api_prefix', $apiPrefix)
            ->withAttribute('tca_api.request_prefix', $matchedRequestPrefix);

        // PHP's SAPI only parses multipart bodies for POST. Parse PUT/PATCH
        // multipart bodies here so update handlers receive form fields and
        // uploaded files via the standard PSR-7 accessors. See issue #143.
        $request = $this->multipartRequestParser->enrich($request);

        // The API middleware short-circuits the frontend RequestHandler, so
        // $GLOBALS['TYPO3_REQUEST'] is never populated for downstream code that
        // expects the b/w-compat global (e.g. RouteEnhancerProcessor reading
        // the current language). Mirror the frontend RequestHandler behavior.
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $this->dispatcher->dispatch($request, $settings);

        if ($corsEnabled) {
            $response = $this->addCorsHeaders($response, $request, $settings);
        }

        return $this->withLocaleHeaders($response, $resolvedLanguage);
    }

    private function buildPreflightResponse(ServerRequestInterface $request, SiteSettings $settings): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(204);
        $response = $this->addCorsHeaders($response, $request, $settings);
        $response = $response->withHeader('Access-Control-Max-Age', '86400');

        return $response;
    }

    private function addCorsHeaders(ResponseInterface $response, ServerRequestInterface $request, SiteSettings $settings): ResponseInterface
    {
        $origin = (string)$settings->get('tca_api.corsOrigin', '*');
        $corsAllowCredentials = (bool)$settings->get('tca_api.corsAllowCredentials', false);

        if ($corsAllowCredentials && $origin === '*') {
            $origin = $request->getHeaderLine('Origin') ?: '*';
        }

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Locale')
            ->withHeader('Access-Control-Expose-Headers', 'Link, X-TCA-API-Cache, X-Cache-Tags, X-Cache-Tag-Count')
            ->withHeader('Vary', 'Origin');

        if ($corsAllowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    private function resolveMatchedRequestPrefix(string $path, string $apiPrefix, ?SiteLanguage $requestLanguage): ?string
    {
        $candidates = [$apiPrefix];

        if ($requestLanguage !== null) {
            $languageBasePath = rtrim($requestLanguage->getBase()->getPath(), '/');
            if ($languageBasePath !== '') {
                $candidates[] = $languageBasePath . $apiPrefix;
            }
        }

        $candidates = array_values(array_unique($candidates));
        usort($candidates, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        foreach ($candidates as $candidate) {
            if ($path === $candidate || str_starts_with($path, $candidate . '/')) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveLanguageOverride(Site $site, string $override): ?SiteLanguage
    {
        if (!ctype_digit($override)) {
            return null;
        }

        $languageId = (int)$override;
        foreach ($site->getLanguages() as $language) {
            if ($language->getLanguageId() === $languageId && $this->isLanguageEnabled($language)) {
                return $language;
            }
        }

        return null;
    }

    private function isLanguageEnabled(SiteLanguage $language): bool
    {
        return $language->enabled();
    }

    private function buildInvalidLanguageResponse(Site $site, string $requested): ResponseInterface
    {
        $enabledLanguageIds = [];
        foreach ($site->getLanguages() as $language) {
            if ($this->isLanguageEnabled($language)) {
                $enabledLanguageIds[] = $language->getLanguageId();
            }
        }

        $body = [
            '@context' => 'http://www.w3.org/ns/hydra/context.jsonld',
            '@type' => 'hydra:Error',
            'hydra:title' => 'Invalid language',
            'hydra:description' => sprintf(
                'Invalid language "%s". Available enabled language ids: %s',
                $requested,
                implode(', ', $enabledLanguageIds),
            ),
        ];

        $response = $this->responseFactory->createResponse(400)
            ->withHeader('Content-Type', 'application/ld+json');
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }

    private function withLocaleHeaders(ResponseInterface $response, ?SiteLanguage $language): ResponseInterface
    {
        $response = $response->withHeader('Content-Language', $this->contentLanguage($language));

        $varyValues = GeneralUtility::trimExplode(',', $response->getHeaderLine('Vary'), true);
        if (!\in_array('X-Locale', $varyValues, true)) {
            $varyValues[] = 'X-Locale';
        }

        return $response->withHeader('Vary', implode(', ', $varyValues));
    }

    private function contentLanguage(?SiteLanguage $language): string
    {
        if ($language === null) {
            return 'en';
        }

        $hreflang = $language->getHreflang();
        if ($hreflang !== '') {
            return $hreflang;
        }

        $languageCode = $language->getLocale()->getLanguageCode();
        return $languageCode !== '' ? $languageCode : 'en';
    }
}
