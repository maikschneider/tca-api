<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Middleware;

use MaikSchneider\TcaApi\Dispatcher\RequestDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

class TcaApiMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestDispatcher $dispatcher,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        $settings = $site instanceof Site ? $site->getSettings() : null;

        if ($settings === null || !$settings->has('tca_api.apiPrefix')) {
            return $handler->handle($request);
        }

        if (!$settings->get('tca_api.enabled', true)) {
            return $handler->handle($request);
        }

        $apiPrefix = rtrim('/' . ltrim((string)$settings->get('tca_api.apiPrefix'), '/'), '/') . '/';

        if (!str_starts_with($request->getUri()->getPath(), $apiPrefix)) {
            return $handler->handle($request);
        }

        $response = $this->dispatcher->dispatch($request, $settings);

        if ($settings->get('tca_api.corsEnabled', false)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', (string)$settings->get('tca_api.corsOrigin', '*'))
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        }

        return $response;
    }
}
