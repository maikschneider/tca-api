<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface OperationHandlerInterface
{
    public function supports(ServerRequestInterface $request, string $operation, ApiDefinition $config): bool;

    public function handle(ServerRequestInterface $request, ApiDefinition $config): ResponseInterface;

    public function getPriority(): int;
}
