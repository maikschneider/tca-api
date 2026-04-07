<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Dispatcher;

use MaikSchneider\TcaApi\OperationHandler\GetCollectionHandler;
use MaikSchneider\TcaApi\OperationHandler\GetItemHandler;
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
    ) {}

    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $segments = explode('/', trim(substr($path, \strlen('/_api')), '/'));

        $resourceName = $segments[0] ?? '';
        $uid = isset($segments[1]) && $segments[1] !== '' ? (int)$segments[1] : null;

        $config = ApiRegistry::get($resourceName);
        if ($config === null) {
            return $this->responseFactory->createResponse(404)
                ->withHeader('Content-Type', 'application/ld+json');
        }

        if ($uid !== null) {
            return $this->itemHandler->handle($request, $config, $uid);
        }

        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? 1));
        $itemsPerPage = max(1, (int)($params['itemsPerPage'] ?? $config['general']['itemsPerPage'] ?? 20));
        $filters = \is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $order = \is_array($params['order'] ?? null) ? $params['order'] : [];

        return $this->collectionHandler->handle($request, $config, $page, $itemsPerPage, $filters, $order);
    }
}
