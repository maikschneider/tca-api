<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

/**
 * Immutable result of a `list` operation: the serialized members plus the
 * pagination state needed to build a Hydra view or a Fluid pager.
 *
 * `$itemsPerPage` and `$page` are the *resolved* values (after default
 * resolution and the maxItemsPerPage clamp), not the raw requested values.
 */
final readonly class CollectionResult
{
    /**
     * @param list<array<string, mixed>> $members Serialized records.
     */
    public function __construct(
        public array $members,
        public int $total,
        public int $page,
        public int $itemsPerPage,
    ) {
    }

    public function totalPages(): int
    {
        return $this->itemsPerPage > 0 ? (int)ceil($this->total / $this->itemsPerPage) : 1;
    }
}
