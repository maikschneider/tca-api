<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Dispatcher;

use MaikSchneider\TcaApi\OperationHandler\GetCollectionHandler;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RequestDispatcher
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly GetCollectionHandler $collectionHandler,
    ) {}

    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $resourceName = trim(substr($path, \strlen('/_api/')), '/');

        $config = ApiRegistry::get($resourceName);
        if ($config === null) {
            return $this->responseFactory->createResponse(404)
                ->withHeader('Content-Type', 'application/ld+json');
        }

        return $this->collectionHandler->handle($request, $config);
    }
}
