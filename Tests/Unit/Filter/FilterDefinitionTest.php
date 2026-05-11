<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\FilterDefinition;
use MaikSchneider\TcaApi\Filter\FilterPreResolvableInterface;
use MaikSchneider\TcaApi\Filter\MmFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterDefinitionTest extends TestCase
{
    // ── fromRaw — simple class-string ───────────────────────────────────

    #[Test]
    public function fromRawWithClassStringCreatesDefinition(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'title', ExactFilter::class);

        self::assertSame(ExactFilter::class, $def->filterClass);
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
        $def = FilterDefinition::fromRaw('tx_test', 'tags', [MmFilter::class, ['mm_table' => 'sys_mm']]);

        self::assertSame(MmFilter::class, $def->filterClass);
        self::assertSame(['mm_table' => 'sys_mm'], $def->options);
        self::assertFalse($def->isPrivate);
        self::assertNull($def->default);
    }

    // ── fromRaw — class only, no second element ──────────────────────────

    #[Test]
    public function fromRawWithClassOnlyArrayCreatesDefinition(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', [ExactFilter::class]);

        self::assertSame(ExactFilter::class, $def->filterClass);
        self::assertSame([], $def->options);
    }

    // ── fromRaw — strips 'private' meta-key ─────────────────────────────

    #[Test]
    public function fromRawStripsPrivateFromOptions(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', [ExactFilter::class, ['private' => true, 'x' => 1]]);

        self::assertTrue($def->isPrivate);
        self::assertSame(['x' => 1], $def->options);
        self::assertArrayNotHasKey('private', $def->options);
    }

    // ── fromRaw — strips 'default' meta-key ─────────────────────────────

    #[Test]
    public function fromRawStripsDefaultFromOptions(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', [ExactFilter::class, ['default' => 'foo', 'x' => 1]]);

        self::assertSame('foo', $def->default);
        self::assertSame(['x' => 1], $def->options);
        self::assertArrayNotHasKey('default', $def->options);
    }

    // ── fromRaw — strips both meta-keys ─────────────────────────────────

    #[Test]
    public function fromRawStripsBothMetaKeys(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', [
            ExactFilter::class,
            ['private' => true, 'default' => 'x', 'type' => 'int'],
        ]);

        self::assertTrue($def->isPrivate);
        self::assertSame('x', $def->default);
        self::assertSame(['type' => 'int'], $def->options);
    }

    // ── fromRaw — invalid class-string (non-existent class) ─────────────

    #[Test]
    public function fromRawWithNonExistentClassThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filter "col"');
        $this->expectExceptionMessage('NonExistent\\Filter');

        FilterDefinition::fromRaw('tx_test', 'col', 'NonExistent\\Filter');
    }

    #[Test]
    public function fromRawWithArrayShapeNonExistentClassThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filter "col"');
        $this->expectExceptionMessage('NonExistent\\Filter');

        FilterDefinition::fromRaw('tx_test', 'col', ['NonExistent\\Filter', []]);
    }

    // ── fromRaw — class not implementing FilterInterface ─────────────────

    #[Test]
    public function fromRawWithClassNotImplementingFilterInterfaceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filter "col"');
        $this->expectExceptionMessage(\stdClass::class);

        FilterDefinition::fromRaw('tx_test', 'col', \stdClass::class);
    }

    #[Test]
    public function fromRawWithArrayShapeClassNotImplementingFilterInterfaceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filter "col"');
        $this->expectExceptionMessage(\stdClass::class);

        FilterDefinition::fromRaw('tx_test', 'col', [\stdClass::class, []]);
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
        $def = FilterDefinition::fromRaw('tx_test', 'col', ExactFilter::class);

        self::assertNull($def->option('type'));
        self::assertSame('fallback', $def->option('type', 'fallback'));
    }

    #[Test]
    public function optionReturnsValueWhenKeyPresent(): void
    {
        $def = FilterDefinition::fromRaw('tx_test', 'col', [ExactFilter::class, ['type' => 'int']]);

        self::assertSame('int', $def->option('type'));
    }

    // ── withOptions() helper ─────────────────────────────────────────────

    #[Test]
    public function withOptionsMergesKeysAndReturnsNewInstance(): void
    {
        $original = FilterDefinition::fromRaw('tx_test', 'col', [ExactFilter::class, ['x' => 1]]);
        $derived  = $original->withOptions(['y' => 2]);

        self::assertNotSame($original, $derived);
        self::assertSame(['x' => 1], $original->options);
        self::assertSame(['x' => 1, 'y' => 2], $derived->options);
    }

    #[Test]
    public function withOptionsOverwritesExistingKey(): void
    {
        $original = FilterDefinition::fromRaw('tx_test', 'col', [ExactFilter::class, ['type' => 'string']]);
        $derived  = $original->withOptions(['type' => 'int']);

        self::assertSame('int', $derived->option('type'));
        self::assertSame('string', $original->option('type'));
    }

    #[Test]
    public function withOptionsPreservesAllOtherProperties(): void
    {
        $def     = FilterDefinition::fromRaw('tx_test', 'col', [ExactFilter::class, ['private' => true, 'default' => 'foo']]);
        $derived = $def->withOptions(['x' => 1]);

        self::assertSame($def->filterClass, $derived->filterClass);
        self::assertSame($def->table, $derived->table);
        self::assertSame($def->column, $derived->column);
        self::assertTrue($derived->isPrivate);
        self::assertSame('foo', $derived->default);
    }

    // ── fromRaw — filterMap preResolve path ──────────────────────────────────

    #[Test]
    public function fromRawCallsPreResolveWhenFilterMapContainsClass(): void
    {
        $extra = new FilterDefinition(
            filterClass: ExactFilter::class,
            table:       'tx_test',
            column:      'col',
            options:     ['derived_key' => 'derived_value'],
        );

        $preResolvable = $this->createMock(FilterPreResolvableInterface::class);
        $preResolvable->method('preResolve')->willReturn($extra);

        $filterMap = [ExactFilter::class => $preResolvable];

        $def = FilterDefinition::fromRaw('tx_test', 'col', ExactFilter::class, $filterMap);

        self::assertSame('derived_value', $def->option('derived_key'));
    }

    #[Test]
    public function fromRawCallsPreResolveForArrayShapeWhenFilterMapContainsClass(): void
    {
        $extra = new FilterDefinition(
            filterClass: ExactFilter::class,
            table:       'tx_test',
            column:      'col',
            options:     ['resolved' => true],
        );

        $preResolvable = $this->createMock(FilterPreResolvableInterface::class);
        $preResolvable->method('preResolve')->willReturn($extra);

        $filterMap = [ExactFilter::class => $preResolvable];

        $def = FilterDefinition::fromRaw('tx_test', 'col', [ExactFilter::class, ['x' => 1]], $filterMap);

        self::assertTrue($def->option('resolved'));
    }

    #[Test]
    public function fromRawUsesOriginalDefWhenFilterMapDoesNotContainClass(): void
    {
        $filterMap = []; // ExactFilter not in map

        $def = FilterDefinition::fromRaw('tx_test', 'col', ExactFilter::class, $filterMap);

        self::assertSame(ExactFilter::class, $def->filterClass);
        self::assertSame([], $def->options);
    }
}
