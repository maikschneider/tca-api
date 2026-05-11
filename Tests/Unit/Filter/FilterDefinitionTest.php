<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use MaikSchneider\TcaApi\Filter\FilterDefinition;
use MaikSchneider\TcaApi\Filter\FilterPreResolvableInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterDefinitionTest extends TestCase
{
    // ── fromRaw — simple class-string ───────────────────────────────────

    #[Test]
    public function fromRawWithClassString(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'title', 'Ns\\ExactFilter');

        self::assertSame('Ns\\ExactFilter', $def->filterClass);
        self::assertSame([], $def->options);
        self::assertFalse($def->isPrivate);
        self::assertNull($def->default);
    }

    // ── fromRaw — class + options ────────────────────────────────────────

    #[Test]
    public function fromRawWithClassAndOptions(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'tags', ['Ns\\MmFilter', ['mm_table' => 'sys_mm']]);

        self::assertSame('Ns\\MmFilter', $def->filterClass);
        self::assertSame(['mm_table' => 'sys_mm'], $def->options);
    }

    #[Test]
    public function fromRawWithClassOnlyArray(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', ['Ns\\MyFilter']);

        self::assertSame('Ns\\MyFilter', $def->filterClass);
        self::assertSame([], $def->options);
    }

    // ── meta-key extraction ─────────────────────────────────────────────

    #[Test]
    public function fromRawStripsPrivateFromOptions(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', ['Ns\\F', ['private' => true, 'x' => 1]]);

        self::assertTrue($def->isPrivate);
        self::assertSame(['x' => 1], $def->options);
    }

    #[Test]
    public function fromRawStripsDefaultFromOptions(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', ['Ns\\F', ['default' => 'foo', 'x' => 1]]);

        self::assertSame('foo', $def->default);
        self::assertSame(['x' => 1], $def->options);
    }

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

    // ── fromRaw — invalid shapes ─────────────────────────────────────────

    #[Test]
    public function fromRawWithIntegerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FilterDefinition::fromRaw('tx_test', 'col', 42);
    }

    #[Test]
    public function fromRawWithNonStringFirstElementThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FilterDefinition::fromRaw('tx_test', 'col', [42, []]);
    }

    #[Test]
    public function fromRawWithNonArrayOptionsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FilterDefinition::fromRaw('tx_test', 'col', ['Ns\\F', 'bad']);
    }

    // ── option() ─────────────────────────────────────────────────────────

    #[Test]
    public function optionReturnsDefault(): void
    {
        $def = new FilterDefinition(filterClass: 'Ns\\F');

        self::assertNull($def->option('missing'));
        self::assertSame('fb', $def->option('missing', 'fb'));
    }

    #[Test]
    public function optionReturnsValue(): void
    {
        $def = new FilterDefinition(filterClass: 'Ns\\F', options: ['type' => 'int']);

        self::assertSame('int', $def->option('type'));
    }

    // ── withOptions() ────────────────────────────────────────────────────

    #[Test]
    public function withOptionsMergesAndReturnsNewInstance(): void
    {
        $orig = new FilterDefinition(filterClass: 'Ns\\F', options: ['x' => 1]);
        $copy = $orig->withOptions(['y' => 2]);

        self::assertNotSame($orig, $copy);
        self::assertSame(['x' => 1], $orig->options);
        self::assertSame(['x' => 1, 'y' => 2], $copy->options);
    }

    #[Test]
    public function withOptionsPreservesScalarProperties(): void
    {
        $orig = new FilterDefinition(filterClass: 'Ns\\F', isPrivate: true, default: 'val');
        $copy = $orig->withOptions(['x' => 1]);

        self::assertTrue($copy->isPrivate);
        self::assertSame('val', $copy->default);
        self::assertSame('Ns\\F', $copy->filterClass);
    }

    // ── preResolve integration ───────────────────────────────────────────

    #[Test]
    public function fromRawCallsPreResolve(): void
    {
        $handler = new class implements FilterPreResolvableInterface {
            public function preResolve(FilterDefinition $definition, string $table, string $column): FilterDefinition
            {
                return $definition->withOptions(['resolved' => $table . '.' . $column]);
            }
        };

        $def = FilterDefinition::fromRaw('tx_test', 'col', $handler::class, [$handler::class => $handler]);

        self::assertSame('tx_test.col', $def->option('resolved'));
    }

    #[Test]
    public function fromRawSkipsPreResolveWhenNotInMap(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', 'Ns\\F', []);

        self::assertNull($def->option('resolved'));
    }
}
