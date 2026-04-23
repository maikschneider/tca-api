<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Event;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched before any API operation is executed.
 *
 * Fired after access control passes, before the operation handler runs.
 * Listeners may stop propagation to short-circuit the operation.
 */
final class BeforeOperationEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    public function __construct(
        private readonly string $operation,
        private readonly ServerRequestInterface $request,
        private readonly ApiDefinition $config,
    ) {
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function getConfig(): ApiDefinition
    {
        return $this->config;
    }

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
