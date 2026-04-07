<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Middleware;

use MaikSchneider\TcaApi\Dispatcher\RequestDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TcaApiMiddleware implements MiddlewareInterface
{
    private const API_PREFIX = '/_api/';

    public function __construct(
        private readonly RequestDispatcher $dispatcher,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (!str_starts_with($path, self::API_PREFIX)) {
            return $handler->handle($request);
        }

        return $this->dispatcher->dispatch($request);
    }
}
