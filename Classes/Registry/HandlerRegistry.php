<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Registry;

use MaikSchneider\TcaApi\OperationHandler\OperationHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[Autoconfigure(public: true)]
final class HandlerRegistry
{
    /** @var list<OperationHandlerInterface> */
    private array $sortedHandlers;

    /**
     * @param iterable<OperationHandlerInterface> $handlers
     */
    public function __construct(
        #[AutowireIterator('tca_api.operation_handler')]
        iterable $handlers,
    ) {
        $all = [];
        foreach ($handlers as $handler) {
            $all[] = $handler;
        }

        usort($all, static fn (OperationHandlerInterface $a, OperationHandlerInterface $b): int => $b->getPriority() <=> $a->getPriority());

        $this->sortedHandlers = $all;
    }

    /**
     * Returns all registered handler instances sorted by priority descending.
     *
     * @return list<OperationHandlerInterface>
     */
    public function getHandlers(): array
    {
        return $this->sortedHandlers;
    }
}
