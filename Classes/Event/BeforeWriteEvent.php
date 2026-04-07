<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Event;

use Psr\EventDispatcher\StoppableEventInterface;

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
    ) {}

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

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
