<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Dispatcher;

use MaikSchneider\TcaApi\OperationHandler\CreateHandler;
use MaikSchneider\TcaApi\OperationHandler\DeleteHandler;
use MaikSchneider\TcaApi\OperationHandler\GetCollectionHandler;
use MaikSchneider\TcaApi\OperationHandler\GetItemHandler;
use MaikSchneider\TcaApi\OperationHandler\UpdateHandler;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
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
    ) {}

    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        $segments = explode('/', trim(substr($path, \strlen('/_api')), '/'));

        $resourceName = $segments[0] ?? '';
        $uid = isset($segments[1]) && $segments[1] !== '' ? (int)$segments[1] : null;

        $config = ApiRegistry::get($resourceName);
        if ($config === null) {
            return $this->notFound();
        }

        return match ($method) {
            'GET' => $uid !== null
                ? $this->itemHandler->handle($request, $config, $uid)
                : $this->handleCollection($request, $config),
            'POST' => $uid === null
                ? $this->createHandler->handle($request, $config)
                : $this->methodNotAllowed(),
            'PUT' => $uid !== null
                ? $this->updateHandler->handle($request, $config, $uid, false)
                : $this->methodNotAllowed(),
            'PATCH' => $uid !== null
                ? $this->updateHandler->handle($request, $config, $uid, true)
                : $this->methodNotAllowed(),
            'DELETE' => $uid !== null
                ? $this->deleteHandler->handle($request, $config, $uid)
                : $this->methodNotAllowed(),
            default => $this->methodNotAllowed(),
        };
    }

    private function handleCollection(ServerRequestInterface $request, array $config): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? 1));
        $itemsPerPage = max(1, (int)($params['itemsPerPage'] ?? $config['general']['itemsPerPage'] ?? 20));
        $filters = \is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $order = \is_array($params['order'] ?? null) ? $params['order'] : [];

        return $this->collectionHandler->handle($request, $config, $page, $itemsPerPage, $filters, $order);
    }

    private function notFound(): ResponseInterface
    {
        return $this->responseFactory->createResponse(404)
            ->withHeader('Content-Type', 'application/ld+json');
    }

    private function methodNotAllowed(): ResponseInterface
    {
        return $this->responseFactory->createResponse(405)
            ->withHeader('Content-Type', 'application/ld+json');
    }
}
