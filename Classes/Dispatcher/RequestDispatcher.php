<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Dispatcher;

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
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RequestDispatcher
{
    private const DEFAULT_ITEMS_PER_PAGE = 20;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly AccessController $accessController,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DataRepository $dataRepository,
        private readonly OpenApiBuilder $openApiBuilder,
    ) {
    }

    public function dispatch(ServerRequestInterface $request, SiteSettings $siteSettings): ResponseInterface
    {
        $method        = strtoupper($request->getMethod());
        $prefixWithout = rtrim((string)$siteSettings->get('tca_api.apiPrefix'), '/');
        $segments      = explode('/', trim(substr($request->getUri()->getPath(), \strlen($prefixWithout)), '/'));
        $resource      = $segments[0] ?? '';
        $uid           = isset($segments[1]) && $segments[1] !== '' ? (int)$segments[1] : null;

        if ($resource === 'openapi.json') {
            if ($method !== 'GET') {
                return $this->methodNotAllowed();
            }

            if (!$siteSettings->get('tca_api.openApiExposed', true)) {
                return $this->notFound();
            }

            return $this->serveOpenApiSpec();
        }

        if (!$this->isResourceAllowed($resource, $siteSettings)) {
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

        if ($operation !== 'userinfo') {
            $allowedOps = $config['general']['operations'] ?? [];
            if (!\in_array($operation, $allowedOps, true)) {
                return $this->methodNotAllowed($operation);
            }
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

        foreach (HandlerRegistry::getHandlers() as $handler) {
            if ($handler->supports($request, $operation, $config)) {
                return $handler->handle($request, $config);
            }
        }

        return $this->methodNotAllowed($operation);
    }

    private function resolveOperation(string $method, ?int $uid, array $config): ?string
    {
        if (($config['general']['type'] ?? '') === 'userinfo') {
            return $method === 'GET' ? 'userinfo' : null;
        }

        return match ($method) {
            'GET'         => $uid !== null ? 'show' : 'list',
            'POST'        => $uid === null ? 'create' : null,
            'PUT', 'PATCH' => $uid !== null ? 'update' : null,
            'DELETE'      => $uid !== null ? 'delete' : null,
            default       => null,
        };
    }

    /**
     * Returns the existing record for update/delete, an empty array when no lookup is needed,
     * or false when the record does not exist (→ 404).
     */
    private function resolveExistingRecord(string $operation, ?int $uid, array $config): array|false
    {
        if ($uid === null || !\in_array($operation, ['update', 'delete'], true)) {
            return [];
        }

        return $this->dataRepository->findById($config['general']['table'], $uid, $config) ?? false;
    }

    private function isResourceAllowed(string $resource, SiteSettings $siteSettings): bool
    {
        $allowed = GeneralUtility::trimExplode(',', (string)$siteSettings->get('tca_api.allowedResources', ''), true);
        return $allowed === [] || \in_array($resource, $allowed, true);
    }

    private function checkAccess(string $operation, ServerRequestInterface $request, array $config, array $existingRecord, SiteSettings $siteSettings): ?ResponseInterface
    {
        if ($operation === 'userinfo') {
            $feUser = $request->getAttribute('frontend.user');
            if ($feUser === null || empty($feUser->user['uid'])) {
                return $this->forbidden('userinfo', $siteSettings);
            }
            return null;
        }

        $requiredRole = $config['security'][$operation] ?? AccessRole::PUBLIC;
        if (!$this->accessController->isAllowed($requiredRole, $request, $existingRecord)) {
            return $this->forbidden($operation, $siteSettings);
        }

        return null;
    }

    private function withRequestAttributes(ServerRequestInterface $request, string $method, ?int $uid, string $operation, array $config, SiteSettings $siteSettings): ServerRequestInterface
    {
        $params = $request->getQueryParams();
        $defaultItemsPerPage = (int)$siteSettings->get('tca_api.defaultItemsPerPage', self::DEFAULT_ITEMS_PER_PAGE);

        return $request
            ->withAttribute('tca_api.uid', $uid)
            ->withAttribute('tca_api.operation', $operation)
            ->withAttribute('tca_api.fields', \is_array($params['fields'] ?? null) ? $params['fields'] : [])
            ->withAttribute('tca_api.page', max(1, (int)($params['page'] ?? 1)))
            ->withAttribute('tca_api.items_per_page', max(1, (int)($params['itemsPerPage'] ?? $config['general']['itemsPerPage'] ?? $defaultItemsPerPage)))
            ->withAttribute('tca_api.filters', \is_array($params['filters'] ?? null) ? $params['filters'] : [])
            ->withAttribute('tca_api.order', \is_array($params['order'] ?? null) ? $params['order'] : [])
            ->withAttribute('tca_api.partial', $method === 'PATCH');
    }

    private function serveOpenApiSpec(): ResponseInterface
    {
        $spec     = $this->openApiBuilder->build();
        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json');
        $response->getBody()->write((string)json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $response;
    }

    private function notFound(): ResponseInterface
    {
        return $this->responseFactory->createResponse(404)
            ->withHeader('Content-Type', 'application/ld+json');
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
