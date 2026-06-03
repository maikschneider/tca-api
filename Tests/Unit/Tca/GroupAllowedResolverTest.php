<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Tca;

use MaikSchneider\TcaApi\Tca\GroupAllowedResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers ISC-1, ISC-2, ISC-4 plus exhaustive branch coverage on the four
 * resolver methods. ISC-3 (boot-time exception) is covered by the loader
 * functional test, not here — this suite stays pure-unit (no $GLOBALS['TCA']).
 */
final class GroupAllowedResolverTest extends TestCase
{
    // ── resolveAllowedTables ─────────────────────────────────────────────

    #[Test]
    public function resolveAllowedTablesReturnsTrimmedListForExplicitAllowed(): void
    {
        $resolver = new GroupAllowedResolver();

        $result = $resolver->resolveAllowedTables([
            'allowed' => 'tx_a, tx_b , tx_c',
        ]);

        self::assertSame(['tx_a', 'tx_b', 'tx_c'], $result);
    }

    #[Test]
    public function resolveAllowedTablesReturnsSingleEntryForSingleAllowedTable(): void
    {
        $resolver = new GroupAllowedResolver();

        $result = $resolver->resolveAllowedTables([
            'allowed' => 'tx_only',
        ]);

        self::assertSame(['tx_only'], $result);
    }

    #[Test]
    public function resolveAllowedTablesReturnsOppositeUsageKeysForWildcardWithOppositeUsage(): void
    {
        $resolver = new GroupAllowedResolver();

        $result = $resolver->resolveAllowedTables([
            'allowed'          => '*',
            'MM_oppositeUsage' => [
                'tx_myext_article' => ['categories'],
                'pages'            => ['categories'],
            ],
        ]);

        self::assertSame(['tx_myext_article', 'pages'], $result);
    }

    #[Test]
    public function resolveAllowedTablesReturnsEmptyForWildcardWithoutOppositeUsage(): void
    {
        $resolver = new GroupAllowedResolver();

        $result = $resolver->resolveAllowedTables([
            'allowed' => '*',
        ]);

        self::assertSame([], $result);
    }

    #[Test]
    public function resolveAllowedTablesReturnsEmptyForMissingAllowedKey(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertSame([], $resolver->resolveAllowedTables([]));
    }

    #[Test]
    public function resolveAllowedTablesReturnsEmptyForEmptyAllowedString(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertSame([], $resolver->resolveAllowedTables(['allowed' => '']));
        self::assertSame([], $resolver->resolveAllowedTables(['allowed' => '   ']));
    }

    #[Test]
    public function resolveAllowedTablesTrimsWhitespaceAroundWildcard(): void
    {
        $resolver = new GroupAllowedResolver();

        $result = $resolver->resolveAllowedTables([
            'allowed'          => '  *  ',
            'MM_oppositeUsage' => ['pages' => ['categories']],
        ]);

        self::assertSame(['pages'], $result);
    }

    // ── isWildcard ────────────────────────────────────────────────────────

    #[Test]
    public function isWildcardReturnsTrueForLiteralStar(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertTrue($resolver->isWildcard(['allowed' => '*']));
    }

    #[Test]
    public function isWildcardReturnsTrueForStarWithWhitespace(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertTrue($resolver->isWildcard(['allowed' => "  *\t"]));
    }

    #[Test]
    public function isWildcardReturnsFalseForExplicitTable(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertFalse($resolver->isWildcard(['allowed' => 'tx_only']));
    }

    #[Test]
    public function isWildcardReturnsFalseForMissingAllowed(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertFalse($resolver->isWildcard([]));
    }

    #[Test]
    public function isWildcardReturnsFalseForCommaSeparatedListContainingStar(): void
    {
        $resolver = new GroupAllowedResolver();

        // A list "*, tx_a" is NOT a wildcard reverse-MM signal — it's malformed
        // TCA, and we conservatively treat it as not-wildcard.
        self::assertFalse($resolver->isWildcard(['allowed' => '*, tx_a']));
    }

    // ── isReverseMmSide ──────────────────────────────────────────────────

    #[Test]
    public function isReverseMmSideReturnsTrueForMmOppositeField(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertTrue($resolver->isReverseMmSide([
            'MM_opposite_field' => 'forward_field',
        ]));
    }

    #[Test]
    public function isReverseMmSideReturnsTrueForMmOppositeUsage(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertTrue($resolver->isReverseMmSide([
            'MM_oppositeUsage' => ['pages' => ['categories']],
        ]));
    }

    #[Test]
    public function isReverseMmSideReturnsTrueWhenBothAreSet(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertTrue($resolver->isReverseMmSide([
            'MM_opposite_field' => 'forward_field',
            'MM_oppositeUsage'  => ['pages' => ['categories']],
        ]));
    }

    #[Test]
    public function isReverseMmSideReturnsFalseForForwardSideMmOnly(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertFalse($resolver->isReverseMmSide([
            'MM'      => 'sys_category_record_mm',
            'allowed' => 'sys_category',
        ]));
    }

    #[Test]
    public function isReverseMmSideReturnsFalseForPlainGroupColumn(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertFalse($resolver->isReverseMmSide([]));
    }

    // ── resolveOppositeUsage ─────────────────────────────────────────────

    #[Test]
    public function resolveOppositeUsageReturnsNormalisedMap(): void
    {
        $resolver = new GroupAllowedResolver();

        $result = $resolver->resolveOppositeUsage([
            'MM_oppositeUsage' => [
                'tx_myext_article' => ['categories', 'tags'],
                'pages'            => ['categories'],
            ],
        ]);

        self::assertSame([
            'tx_myext_article' => ['categories', 'tags'],
            'pages'            => ['categories'],
        ], $result);
    }

    #[Test]
    public function resolveOppositeUsageReturnsEmptyForMissingKey(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertSame([], $resolver->resolveOppositeUsage([]));
    }

    #[Test]
    public function resolveOppositeUsageReturnsEmptyForNonArrayValue(): void
    {
        $resolver = new GroupAllowedResolver();

        self::assertSame([], $resolver->resolveOppositeUsage(['MM_oppositeUsage' => 'not-an-array']));
    }

    #[Test]
    public function resolveOppositeUsageDropsEntriesWithInvalidTableNames(): void
    {
        $resolver = new GroupAllowedResolver();

        $result = $resolver->resolveOppositeUsage([
            'MM_oppositeUsage' => [
                'pages' => ['categories'],
                ''      => ['categories'], // empty key — dropped
                42      => ['categories'], // numeric key — dropped (not a string)
            ],
        ]);

        self::assertSame(['pages' => ['categories']], $result);
    }

    #[Test]
    public function resolveOppositeUsageDropsEntriesWithNonArrayFieldLists(): void
    {
        $resolver = new GroupAllowedResolver();

        $result = $resolver->resolveOppositeUsage([
            'MM_oppositeUsage' => [
                'pages'             => ['categories'],
                'tx_myext_article'  => 'categories', // not an array — dropped
            ],
        ]);

        self::assertSame(['pages' => ['categories']], $result);
    }

    #[Test]
    public function resolveOppositeUsageFiltersOutNonStringFieldNames(): void
    {
        $resolver = new GroupAllowedResolver();

        $result = $resolver->resolveOppositeUsage([
            'MM_oppositeUsage' => [
                'pages' => ['categories', '', 7, 'tags'],
            ],
        ]);

        self::assertSame(['pages' => ['categories', 'tags']], $result);
    }
}
