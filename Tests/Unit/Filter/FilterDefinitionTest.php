<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use MaikSchneider\TcaApi\Filter\FilterDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterDefinitionTest extends TestCase
{
    // ── fromRaw — simple class-string ───────────────────────────────────

    #[Test]
    public function fromRawWithClassStringCreatesDefinition(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'title', 'Ns\\ExactFilter');

        self::assertSame('Ns\\ExactFilter', $def->filterClass);
        self::assertSame('tx_test', $def->table);
        self::assertSame('title', $def->column);
        self::assertSame([], $def->options);
        self::assertFalse($def->isPrivate);
        self::assertNull($def->default);
    }

    // ── fromRaw — class + options ────────────────────────────────────────

    #[Test]
    public function fromRawWithClassAndOptionsCreatesDefinition(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'tags', ['Ns\\MmFilter', ['mm_table' => 'sys_mm']]);

        self::assertSame('Ns\\MmFilter', $def->filterClass);
        self::assertSame(['mm_table' => 'sys_mm'], $def->options);
        self::assertFalse($def->isPrivate);
        self::assertNull($def->default);
    }

    // ── fromRaw — class only, no second element ──────────────────────────

    #[Test]
    public function fromRawWithClassOnlyArrayCreatesDefinition(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', ['Ns\\MyFilter']);

        self::assertSame('Ns\\MyFilter', $def->filterClass);
        self::assertSame([], $def->options);
    }

    // ── fromRaw — strips 'private' meta-key ─────────────────────────────

    #[Test]
    public function fromRawStripsPrivateFromOptions(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', ['Ns\\F', ['private' => true, 'x' => 1]]);

        self::assertTrue($def->isPrivate);
        self::assertSame(['x' => 1], $def->options);
        self::assertArrayNotHasKey('private', $def->options);
    }

    // ── fromRaw — strips 'default' meta-key ─────────────────────────────

    #[Test]
    public function fromRawStripsDefaultFromOptions(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', ['Ns\\F', ['default' => 'foo', 'x' => 1]]);

        self::assertSame('foo', $def->default);
        self::assertSame(['x' => 1], $def->options);
        self::assertArrayNotHasKey('default', $def->options);
    }

    // ── fromRaw — strips both meta-keys ─────────────────────────────────

    #[Test]
    public function fromRawStripsBothMetaKeys(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', [
            'Ns\\F',
            ['private' => true, 'default' => 'x', 'type' => 'int'],
        ]);

        self::assertTrue($def->isPrivate);
        self::assertSame('x', $def->default);
        self::assertSame(['type' => 'int'], $def->options);
    }

    // ── fromRaw — invalid shape ──────────────────────────────────────────

    #[Test]
    public function fromRawWithIntegerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('myCol');

        FilterDefinition::fromRaw('tx_test', 'myCol', 42);
    }

    #[Test]
    public function fromRawWithArrayNonStringFirstElementThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FilterDefinition::fromRaw('tx_test', 'col', [42, []]);
    }

    // ── option() helper ──────────────────────────────────────────────────

    #[Test]
    public function optionReturnsDefaultWhenKeyAbsent(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', 'Ns\\F');

        self::assertNull($def->option('type'));
        self::assertSame('fallback', $def->option('type', 'fallback'));
    }

    #[Test]
    public function optionReturnsValueWhenKeyPresent(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', ['Ns\\F', ['type' => 'int']]);

        self::assertSame('int', $def->option('type'));
    }

    // ── withOptions() helper ─────────────────────────────────────────────

    #[Test]
    public function withOptionsMergesKeysAndReturnsNewInstance(): void
    {
        $original = FilterDefinition::fromRaw('tx_test', 'col', ['Ns\\F', ['x' => 1]]);
        $derived  = $original->withOptions(['y' => 2]);

        self::assertNotSame($original, $derived);
        self::assertSame(['x' => 1], $original->options);
        self::assertSame(['x' => 1, 'y' => 2], $derived->options);
    }

    #[Test]
    public function withOptionsOverwritesExistingKey(): void
    {
        $original = FilterDefinition::fromRaw('tx_test', 'col', ['Ns\\F', ['type' => 'string']]);
        $derived  = $original->withOptions(['type' => 'int']);

        self::assertSame('int', $derived->option('type'));
        self::assertSame('string', $original->option('type'));
    }

    #[Test]
    public function withOptionsPreservesAllOtherProperties(): void
    {
        $def     = FilterDefinition::fromRaw('tx_test', 'col', ['Ns\\F', ['private' => true, 'default' => 'foo']]);
        $derived = $def->withOptions(['x' => 1]);

        self::assertSame($def->filterClass, $derived->filterClass);
        self::assertSame($def->table, $derived->table);
        self::assertSame($def->column, $derived->column);
        self::assertTrue($derived->isPrivate);
        self::assertSame('foo', $derived->default);
    }
}
