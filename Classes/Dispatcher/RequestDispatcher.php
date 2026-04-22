<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Dispatcher;

use MaikSchneider\TcaApi\Cache\CacheTagCollector;
use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Event\BeforeOperationEvent;
use MaikSchneider\TcaApi\OpenApi\OpenApiBuilder;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Registry\HandlerRegistry;
use MaikSchneider\TcaApi\Security\AccessController;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

final class RequestDispatcher
{
    private const DEFAULT_ITEMS_PER_PAGE = 20;
    private const RESOURCE_OPENAPI = 'openapi.json';
    private const RESOURCE_SWAGGER_UI = 'swagger-ui';

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly AccessController $accessController,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DataRepository $dataRepository,
        private readonly CacheTagCollector $cacheTagCollector,
        private readonly FrontendInterface $cache,
    ) {
    }

    public function dispatch(ServerRequestInterface $request, SiteSettings $siteSettings): ResponseInterface
    {
        $method        = strtoupper($request->getMethod());
        $prefixWithout = rtrim((string)$siteSettings->get('tca_api.apiPrefix'), '/');
        $segments      = explode('/', trim(substr($request->getUri()->getPath(), \strlen($prefixWithout)), '/'));
        $resource      = $segments[0];
        $uid           = isset($segments[1]) && $segments[1] !== '' ? (int)$segments[1] : null;

        if ($resource === self::RESOURCE_OPENAPI) {
            if ($method !== 'GET') {
                return $this->methodNotAllowed();
            }

            $role = AccessRole::tryFrom((string)$siteSettings->get('tca_api.openApiExposed', 'PUBLIC'));
            if ($role === null || !$this->accessController->isAllowed($role, $request)) {
                return $this->notFound();
            }

            return $this->serveOpenApiSpec($siteSettings);
        }

        if ($resource === self::RESOURCE_SWAGGER_UI) {
            if ($method !== 'GET') {
                return $this->methodNotAllowed();
            }

            $role = AccessRole::tryFrom((string)$siteSettings->get('tca_api.swaggerUiEnabled', 'NONE'));
            if ($role === null || !$this->accessController->isAllowed($role, $request)) {
                return $this->notFound();
            }

            return $this->serveSwaggerUi($prefixWithout);
        }

        if (!$this->isResourceInSiteAllowed($resource, $siteSettings)) {
            return $this->notFound();
        }

        $config = ApiRegistry::get($resource);
        if ($config === null) {
            return $this->notFound();
        }

        $operation = $this->resolveOperation($method, $uid, $config);
        if ($operation === null) {
            return $this->methodNotAllowed();
        }

        if ($operation !== 'userinfo' && !$config->hasOperation($operation)) {
            return $this->methodNotAllowed($operation);
        }

        $existingRecord = $this->resolveExistingRecord($operation, $uid, $config);
        if ($existingRecord === false) {
            return $this->notFound();
        }

        $accessError = $this->checkAccess($operation, $request, $config, $existingRecord, $siteSettings);
        if ($accessError !== null) {
            return $accessError;
        }

        $this->eventDispatcher->dispatch(new BeforeOperationEvent($operation, $request, $config));

        $request = $this->withRequestAttributes($request, $method, $uid, $operation, $config, $siteSettings);

        // ── Cache: check for hit on cacheable read operations ────────────
        $cacheKey = null;
        if ($config->cache->enabled && \in_array($operation, ['list', 'show'], true)) {
            $cacheKey = $this->buildCacheKey($operation, $config, $request);
        }

        if ($cacheKey !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== false) {
                $response = $this->responseFactory->createResponse(200)
                    ->withHeader('Content-Type', 'application/ld+json')
                    ->withHeader('X-TCA-API-Cache', 'HIT');
                $response->getBody()->write((string)$cached);
                return $response;
            }
        }

        // ── Activate cache tag collection for cache-miss path ────────────
        if ($cacheKey !== null) {
            $this->cacheTagCollector->activate();
        }

        $response = null;
        foreach (HandlerRegistry::getHandlers() as $handler) {
            if ($handler->supports($request, $operation, $config)) {
                $response = $handler->handle($request, $config);
                break;
            }
        }

        if ($response === null) {
            $this->cacheTagCollector->reset();
            return $this->methodNotAllowed($operation);
        }

        // ── Cache: store response and attach tags on miss ────────────────
        if ($cacheKey !== null) {
            $body = (string)$response->getBody();
            $tags = $this->cacheTagCollector->getTags();
            $this->cacheTagCollector->reset();

            $this->cache->set($cacheKey, $body, $tags, $config->cache->lifetime);

            $response = $response->withHeader('X-TCA-API-Cache', 'MISS');
            if ($tags !== []) {
                $response = $response->withHeader('X-Cache-Tags', implode(',', $tags));
            }
        }

        return $response;
    }

    private function resolveOperation(string $method, ?int $uid, ApiDefinition $config): ?string
    {
        if ($config->isUserInfo()) {
            return $method === 'GET' ? 'userinfo' : null;
        }

        return match ($method) {
            'GET'          => $uid !== null ? 'show' : 'list',
            'POST'         => $uid === null ? 'create' : null,
            'PUT', 'PATCH' => $uid !== null ? 'update' : null,
            'DELETE'       => $uid !== null ? 'delete' : null,
            default        => null,
        };
    }

    /**
     * Returns the existing record for update/delete, an empty array when no lookup is needed,
     * or false when the record does not exist (→ 404).
     */
    private function resolveExistingRecord(string $operation, ?int $uid, ApiDefinition $config): array|false
    {
        if ($uid === null || !\in_array($operation, ['update', 'delete'], true)) {
            return [];
        }

        return $this->dataRepository->findById($config->table, $uid, $config) ?? false;
    }

    private function isResourceInSiteAllowed(string $resource, SiteSettings $siteSettings): bool
    {
        $allowed = GeneralUtility::trimExplode(',', (string)$siteSettings->get('tca_api.allowedResources', ''), true);
        return $allowed === [] || \in_array($resource, $allowed, true);
    }

    private function checkAccess(string $operation, ServerRequestInterface $request, ApiDefinition $config, array $existingRecord, SiteSettings $siteSettings): ?ResponseInterface
    {
        if ($operation === 'userinfo') {
            $feUser = $request->getAttribute('frontend.user');
            if ($feUser === null || empty($feUser->user['uid'])) {
                return $this->forbidden('userinfo', $siteSettings);
            }
            return null;
        }

        $requiredRole = $config->securityRole($operation);
        if (!$this->accessController->isAllowed($requiredRole, $request, $existingRecord, $config)) {
            return $this->forbidden($operation, $siteSettings);
        }

        return null;
    }

    private function withRequestAttributes(ServerRequestInterface $request, string $method, ?int $uid, string $operation, ApiDefinition $config, SiteSettings $siteSettings): ServerRequestInterface
    {
        $params = $request->getQueryParams();
        $defaultItemsPerPage = (int)$siteSettings->get('tca_api.defaultItemsPerPage', self::DEFAULT_ITEMS_PER_PAGE);
        $itemsPerPage = max(1, (int)($params['itemsPerPage'] ?? $config->itemsPerPage ?? $defaultItemsPerPage));
        if ($config->maxItemsPerPage !== null) {
            $itemsPerPage = min($itemsPerPage, $config->maxItemsPerPage);
        }

        return $request
            ->withAttribute('tca_api.uid', $uid)
            ->withAttribute('tca_api.operation', $operation)
            ->withAttribute('tca_api.fields', \is_array($params['fields'] ?? null) ? $params['fields'] : [])
            ->withAttribute('tca_api.page', max(1, (int)($params['page'] ?? 1)))
            ->withAttribute('tca_api.items_per_page', $itemsPerPage)
            ->withAttribute('tca_api.filters', \is_array($params['filters'] ?? null) ? $params['filters'] : [])
            ->withAttribute('tca_api.order', \is_array($params['order'] ?? null) ? $params['order'] : [])
            ->withAttribute('tca_api.partial', $method === 'PATCH');
    }

    private function serveOpenApiSpec(SiteSettings $siteSettings): ResponseInterface
    {
        $openApiBuilder = new OpenApiBuilder($siteSettings);
        $spec     = $openApiBuilder->build();
        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json');
        $response->getBody()->write((string)json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $response;
    }

    private function serveSwaggerUi(string $apiPrefixWithout): ResponseInterface
    {
        $openApiUrl = json_encode($apiPrefixWithout . '/openapi.json');
        $cssUrl     = PathUtility::getPublicResourceWebPath('EXT:tca_api/Resources/Public/SwaggerUI/swagger-ui.css');
        $jsUrl      = PathUtility::getPublicResourceWebPath('EXT:tca_api/Resources/Public/SwaggerUI/swagger-ui-bundle.js');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>API Documentation</title>
                <link rel="stylesheet" href="{$cssUrl}">
                <style>
                    body { margin: 0; }
                    .swagger-ui .topbar { display: none; }
                </style>
            </head>
            <body>
            <div id="swagger-ui"></div>
            <script src="{$jsUrl}"></script>
            <script>
                SwaggerUIBundle({
                    url: {$openApiUrl},
                    dom_id: '#swagger-ui',
                    presets: [SwaggerUIBundle.presets.apis],
                    layout: 'BaseLayout',
                    deepLinking: true
                });
            </script>
            </body>
            </html>
            HTML;

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Generate a deterministic cache key from operation, resource, and relevant query parameters.
     *
     * Returns null when an ignored parameter is present in the request (= bypass caching).
     */
    private function buildCacheKey(string $operation, ApiDefinition $config, ServerRequestInterface $request): ?string
    {
        $queryParams = $request->getQueryParams();
        $ignoredParams = $config->cache->parametersToIgnore;

        // If any ignored parameter is present, bypass caching entirely
        foreach ($ignoredParams as $ignored) {
            if (isset($queryParams[$ignored]) || (isset($queryParams['filters']) && \is_array($queryParams['filters']) && isset($queryParams['filters'][$ignored]))) {
                return null;
            }
        }

        // Build a deterministic set of relevant parameters
        $relevant = [];
        foreach (['page', 'itemsPerPage', 'filters', 'order', 'fields'] as $key) {
            if (isset($queryParams[$key])) {
                $relevant[$key] = $queryParams[$key];
            }
        }
        ksort($relevant);

        $uid = $request->getAttribute('tca_api.uid');
        $uidPart = $uid !== null ? ':' . $uid : '';

        return md5($operation . ':' . $config->resourceName . $uidPart . ':' . json_encode($relevant, JSON_THROW_ON_ERROR));
    }

    private function notFound(): ResponseInterface
    {
        return $this->hydraResponseBuilder->buildError(404, 'Resource not found', 'Not Found');
    }

    private function methodNotAllowed(?string $operation = null): ResponseInterface
    {
        $description = $operation !== null
            ? sprintf("Operation '%s' is not available on this resource", $operation)
            : 'Method not allowed';

        return $this->hydraResponseBuilder->buildError(405, $description, 'Method Not Allowed');
    }

    private function forbidden(string $operation, SiteSettings $siteSettings): ResponseInterface
    {
        $description = (bool)$siteSettings->get('tca_api.debugMode', false)
            ? 'Insufficient permissions for operation: ' . $operation
            : 'Access Denied';

        return $this->hydraResponseBuilder->buildError(403, $description, 'Access Denied');
    }
}
