<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use MaikSchneider\TcaApi\Filter\FilterContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterContextTest extends TestCase
{
    // ── option() helper ──────────────────────────────────────────────────

    #[Test]
    public function optionReturnsNullWhenKeyAbsent(): void
    {
        $ctx = new FilterContext(value: 'foo', table: 'tx_test', column: 'title');

        self::assertNull($ctx->option('type'));
    }

    #[Test]
    public function optionReturnsProvidedDefaultWhenKeyAbsent(): void
    {
        $ctx = new FilterContext(value: 'foo', table: 'tx_test', column: 'title');

        self::assertSame('fallback', $ctx->option('type', 'fallback'));
    }

    #[Test]
    public function optionReturnsValueWhenKeyPresent(): void
    {
        $ctx = new FilterContext(value: 'foo', table: 'tx_test', column: 'title', options: ['type' => 'int']);

        self::assertSame('int', $ctx->option('type'));
    }

    // ── withOptions() helper ─────────────────────────────────────────────

    #[Test]
    public function withOptionsMergesExtraKeysIntoNewInstance(): void
    {
        $original = new FilterContext(value: 'bar', table: 'tx_test', column: 'col', options: ['x' => 1]);
        $derived  = $original->withOptions(['y' => 2]);

        self::assertNotSame($original, $derived);
        self::assertSame(['x' => 1, 'y' => 2], $derived->options);
    }

    #[Test]
    public function withOptionsDoesNotMutateOriginal(): void
    {
        $original = new FilterContext(value: 'bar', table: 'tx_test', column: 'col', options: ['x' => 1]);
        $original->withOptions(['y' => 2]);

        self::assertSame(['x' => 1], $original->options);
    }

    #[Test]
    public function withOptionsOverwritesExistingKey(): void
    {
        $original = new FilterContext(value: 'v', table: 'tx_test', column: 'col', options: ['type' => 'string']);
        $derived  = $original->withOptions(['type' => 'int']);

        self::assertSame('int', $derived->option('type'));
        self::assertSame('string', $original->option('type'));
    }

    #[Test]
    public function withOptionsPreservesAllOtherProperties(): void
    {
        $ctx     = new FilterContext(value: 42, table: 'tx_ext', column: 'pid', options: ['x' => 1]);
        $derived = $ctx->withOptions(['y' => 99]);

        self::assertSame(42, $derived->value);
        self::assertSame('tx_ext', $derived->table);
        self::assertSame('pid', $derived->column);
        self::assertNull($derived->request);
        self::assertNull($derived->resourceConfig);
    }
}
