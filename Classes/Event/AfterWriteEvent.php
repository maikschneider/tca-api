<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Event;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched after DataHandler successfully writes a record.
 *
 * Carries the resulting UID — useful for cache invalidation, audit logs,
 * post-write side-effects, etc.
 */
final class AfterWriteEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    public function __construct(
        private readonly string $table,
        private readonly string $operation,
        private readonly int $uid,
        private readonly ServerRequestInterface $request,
        private readonly ApiDefinition $config,
    ) {
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getUid(): int
    {
        return $this->uid;
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
