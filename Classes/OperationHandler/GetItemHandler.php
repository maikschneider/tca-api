<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class GetItemHandler
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function supports(string $httpMethod, string $operation): bool
    {
        return $httpMethod === 'GET' && $operation === 'show';
    }

    public function handle(ServerRequestInterface $request, array $config, int $uid): ResponseInterface
    {
        // TODO: query DB by uid, serialize, build Hydra response
        return $this->responseFactory->createResponse(501);
    }

    public function getPriority(): int
    {
        return 10;
    }
}
