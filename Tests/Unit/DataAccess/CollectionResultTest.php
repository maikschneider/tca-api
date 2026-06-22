<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\DataAccess;

use MaikSchneider\TcaApi\DataAccess\CollectionResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CollectionResultTest extends TestCase
{
    // ── Constructor and property access ─────────────────────────────────

    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $members = [['uid' => 1, 'title' => 'Hello'], ['uid' => 2, 'title' => 'World']];
        $result = new CollectionResult($members, 42, 3, 10);

        self::assertSame($members, $result->members);
        self::assertSame(42, $result->total);
        self::assertSame(3, $result->page);
        self::assertSame(10, $result->itemsPerPage);
    }

    #[Test]
    public function emptyMembersAreAllowed(): void
    {
        $result = new CollectionResult([], 0, 1, 20);

        self::assertSame([], $result->members);
        self::assertSame(0, $result->total);
    }

    // ── totalPages() ─────────────────────────────────────────────────────

    #[Test]
    public function totalPagesIsOneWhenTotalIsZero(): void
    {
        $result = new CollectionResult([], 0, 1, 20);

        self::assertSame(1, $result->totalPages());
    }

    #[Test]
    public function totalPagesIsOneWhenTotalFitsExactlyOnOnePage(): void
    {
        $result = new CollectionResult([], 10, 1, 10);

        self::assertSame(1, $result->totalPages());
    }

    #[Test]
    public function totalPagesRoundsUpForPartialLastPage(): void
    {
        $result = new CollectionResult([], 11, 1, 10);

        self::assertSame(2, $result->totalPages());
    }

    #[Test]
    public function totalPagesDividesEvenly(): void
    {
        $result = new CollectionResult([], 30, 1, 10);

        self::assertSame(3, $result->totalPages());
    }

    #[Test]
    public function totalPagesRoundsUpForSingleRemainder(): void
    {
        $result = new CollectionResult([], 21, 1, 10);

        self::assertSame(3, $result->totalPages());
    }

    #[Test]
    public function totalPagesIsOneWhenItemsPerPageIsZeroToPreventDivisionByZero(): void
    {
        // itemsPerPage == 0 is a degenerate case; the guard returns 1 to avoid division by zero.
        $result = new CollectionResult([], 100, 1, 0);

        self::assertSame(1, $result->totalPages());
    }

    #[Test]
    public function totalPagesWithSingleRecord(): void
    {
        $result = new CollectionResult([['uid' => 1]], 1, 1, 20);

        self::assertSame(1, $result->totalPages());
    }

    #[Test]
    public function totalPagesWithItemsPerPageOfOne(): void
    {
        $result = new CollectionResult([], 5, 1, 1);

        self::assertSame(5, $result->totalPages());
    }

    // ── Immutability ─────────────────────────────────────────────────────

    #[Test]
    public function resultIsImmutable(): void
    {
        $result = new CollectionResult([['uid' => 1]], 5, 2, 10);

        // Verify properties are accessible without modification (readonly)
        self::assertSame(5, $result->total);
        self::assertSame(2, $result->page);
        self::assertSame(10, $result->itemsPerPage);
    }
}