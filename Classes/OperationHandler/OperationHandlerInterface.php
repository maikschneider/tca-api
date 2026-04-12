<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface OperationHandlerInterface
{
    public function supports(ServerRequestInterface $request, string $operation, array $config): bool;

    public function handle(ServerRequestInterface $request, array $config): ResponseInterface;

    public function getPriority(): int;
}
