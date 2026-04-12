<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Dispatcher;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Event\BeforeOperationEvent;
use MaikSchneider\TcaApi\OpenApi\OpenApiBuilder;
use MaikSchneider\TcaApi\OperationHandler\CreateHandler;
use MaikSchneider\TcaApi\OperationHandler\DeleteHandler;
use MaikSchneider\TcaApi\OperationHandler\GetCollectionHandler;
use MaikSchneider\TcaApi\OperationHandler\GetItemHandler;
use MaikSchneider\TcaApi\OperationHandler\GetUserInfoHandler;
use MaikSchneider\TcaApi\OperationHandler\UpdateHandler;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Security\AccessController;
use MaikSchneider\TcaApi\Serializer\HydraResponseBuilder;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RequestDispatcher
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly GetCollectionHandler $collectionHandler,
        private readonly GetItemHandler $itemHandler,
        private readonly CreateHandler $createHandler,
        private readonly UpdateHandler $updateHandler,
        private readonly DeleteHandler $deleteHandler,
        private readonly GetUserInfoHandler $userinfoHandler,
        private readonly AccessController $accessController,
        private readonly HydraResponseBuilder $hydraResponseBuilder,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DataRepository $dataRepository,
        private readonly OpenApiBuilder $openApiBuilder,
    ) {
    }

    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        $segments = explode('/', trim(substr($path, \strlen('/_api')), '/'));

        $resourceName = $segments[0] ?? '';
        $uid = isset($segments[1]) && $segments[1] !== '' ? (int)$segments[1] : null;

        if ($resourceName === 'openapi.json' && $method === 'GET') {
            return $this->serveOpenApiSpec();
        }

        $config = ApiRegistry::get($resourceName);
        if ($config === null) {
            return $this->notFound();
        }

        if (($config['general']['type'] ?? '') === 'userinfo') {
            if ($method !== 'GET') {
                return $this->methodNotAllowed();
            }
            $feUserAttr = $request->getAttribute('frontend.user');
            if ($feUserAttr === null || empty($feUserAttr->user['uid'])) {
                return $this->forbidden('userinfo');
            }
            $this->eventDispatcher->dispatch(new BeforeOperationEvent('userinfo', $request, $config));
            $fields = \is_array($request->getQueryParams()['fields'] ?? null) ? $request->getQueryParams()['fields'] : [];
            return $this->userinfoHandler->handle($request, $config, $fields);
        }

        $operation = match ($method) {
            'GET'    => $uid !== null ? 'show' : 'list',
            'POST'   => $uid === null ? 'create' : null,
            'PUT'    => $uid !== null ? 'update' : null,
            'PATCH'  => $uid !== null ? 'update' : null,
            'DELETE' => $uid !== null ? 'delete' : null,
            default  => null,
        };

        if ($operation === null) {
            return $this->methodNotAllowed();
        }

        $allowedOps = $config['general']['operations'] ?? [];
        if (!\in_array($operation, $allowedOps, true)) {
            return $this->methodNotAllowed($operation);
        }

        $existingRecord = [];
        if ($uid !== null && ($operation === 'update' || $operation === 'delete')) {
            $existingRecord = $this->dataRepository->findById($config['general']['table'], $uid, $config) ?? [];
            if ($existingRecord === []) {
                return $this->notFound();
            }
        }

        $requiredRole = $config['security'][$operation] ?? AccessRole::PUBLIC;
        if (!$this->accessController->isAllowed($requiredRole, $request, $existingRecord)) {
            return $this->forbidden($operation);
        }

        $this->eventDispatcher->dispatch(new BeforeOperationEvent($operation, $request, $config));

        $params = $request->getQueryParams();
        $fields = \is_array($params['fields'] ?? null) ? $params['fields'] : [];

        return match ($operation) {
            'list'   => $this->handleCollection($request, $config, $fields),
            'show'   => $this->itemHandler->handle($request, $config, $uid, $fields),
            'create' => $this->createHandler->handle($request, $config),
            'update' => $this->updateHandler->handle($request, $config, $uid, $method === 'PATCH'),
            'delete' => $this->deleteHandler->handle($request, $config, $uid),
        };
    }

    private function handleCollection(ServerRequestInterface $request, array $config, array $fields): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? 1));
        $itemsPerPage = max(1, (int)($params['itemsPerPage'] ?? $config['general']['itemsPerPage'] ?? 20));
        $filters = \is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $order = \is_array($params['order'] ?? null) ? $params['order'] : [];

        return $this->collectionHandler->handle($request, $config, $page, $itemsPerPage, $filters, $order, $fields);
    }

    private function serveOpenApiSpec(): ResponseInterface
    {
        $spec = $this->openApiBuilder->build();
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

    private function forbidden(string $operation): ResponseInterface
    {
        return $this->hydraResponseBuilder->buildError(
            403,
            'Insufficient permissions for operation: ' . $operation,
            'Access Denied',
        );
    }
}
