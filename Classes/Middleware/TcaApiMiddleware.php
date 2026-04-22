<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Middleware;

use MaikSchneider\TcaApi\Dispatcher\RequestDispatcher;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

final class TcaApiMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestDispatcher $dispatcher,
        private readonly ResponseFactoryInterface $responseFactory,
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

        $apiPrefix = rtrim('/' . ltrim((string)$settings->get('tca_api.apiPrefix'), '/'), '/');

        if (!str_starts_with($request->getUri()->getPath(), $apiPrefix . '/')) {
            return $handler->handle($request);
        }

        $corsEnabled = (bool)$settings->get('tca_api.corsEnabled', false);

        if ($corsEnabled && strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->buildPreflightResponse($settings);
        }

        $request = $request->withAttribute('tca_api.api_prefix', $apiPrefix);
        $response = $this->dispatcher->dispatch($request, $settings);

        if ($corsEnabled) {
            $response = $this->addCorsHeaders($response, $settings);
        }

        return $response;
    }

    private function buildPreflightResponse(SiteSettings $settings): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(204);
        $response = $this->addCorsHeaders($response, $settings);
        $response = $response->withHeader('Access-Control-Max-Age', '86400');

        return $response;
    }

    private function addCorsHeaders(ResponseInterface $response, SiteSettings $settings): ResponseInterface
    {
        $origin = (string)$settings->get('tca_api.corsOrigin', '*');

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Vary', 'Origin');

        if ((bool)$settings->get('tca_api.corsAllowCredentials', false)) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
