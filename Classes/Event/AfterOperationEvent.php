<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Event;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched after any API operation completes, before the response is sent.
 *
 * For collection and item reads, $data is the serialized result array.
 * Listeners may mutate $data to add computed fields or filter output.
 */
final class AfterOperationEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    public function __construct(
        private readonly string $operation,
        private array $data,
        private readonly ServerRequestInterface $request,
        private readonly ApiDefinition $config,
    ) {
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

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
