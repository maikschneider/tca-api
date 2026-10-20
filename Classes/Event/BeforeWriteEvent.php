<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Event;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched before DataHandler writes a record (create, update, delete).
 *
 * Listeners may modify $data to alter what gets written to the database,
 * or call stopPropagation() to abort the write entirely.
 */
final class BeforeWriteEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    public function __construct(
        private readonly string $table,
        private readonly string $operation,
        private array $data,
        private readonly ServerRequestInterface $request,
        private readonly ApiDefinition $config,
        private readonly ?int $uid,
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

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function getConfig(): ApiDefinition
    {
        return $this->config;
    }

    /**
     * The uid of the record being updated or deleted, null on create.
     */
    public function getUid(): ?int
    {
        return $this->uid;
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
