<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use MaikSchneider\TcaApi\Filter\FilterContext;
use MaikSchneider\TcaApi\Filter\FilterValueException;
use MaikSchneider\TcaApi\Filter\ValueSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValueSetTest extends TestCase
{
    #[Test]
    public function scalarValueBecomesSingleEntry(): void
    {
        $set = ValueSet::fromContext($this->context('5'));

        self::assertSame(['5'], $set->values);
        self::assertFalse($set->isMulti());
        self::assertFalse($set->isEmpty());
        self::assertFalse($set->negate);
    }

    #[Test]
    public function emptyStringStaysAValue(): void
    {
        $set = ValueSet::fromContext($this->context(''));

        self::assertSame([''], $set->values);
        self::assertFalse($set->isEmpty());
    }

    #[Test]
    public function listValueBecomesMultiEntry(): void
    {
        $set = ValueSet::fromContext($this->context(['5', '9']));

        self::assertSame(['5', '9'], $set->values);
        self::assertTrue($set->isMulti());
    }

    #[Test]
    public function duplicatesAreCollapsed(): void
    {
        $set = ValueSet::fromContext($this->context(['5', '5', '9']));

        self::assertSame(['5', '9'], $set->values);
    }

    #[Test]
    public function singleEntryListIsNotMulti(): void
    {
        $set = ValueSet::fromContext($this->context(['5']));

        self::assertFalse($set->isMulti());
        self::assertSame('5', $set->first());
    }

    #[Test]
    public function emptyListIsEmpty(): void
    {
        self::assertTrue(ValueSet::fromContext($this->context([]))->isEmpty());
    }

    /**
     * @param list<scalar|null> $value
     * @param list<scalar> $expected
     */
    #[Test]
    #[DataProvider('blankArrayValues')]
    public function blankArrayEntriesAreDropped(array $value, array $expected): void
    {
        $set = ValueSet::fromContext($this->context($value));

        self::assertSame($expected, $set->values);
        self::assertSame($expected === [], $set->isEmpty());
    }

    /** @return iterable<string, array{list<scalar|null>, list<scalar>}> */
    public static function blankArrayValues(): iterable
    {
        yield 'empty string' => [[''], []];
        yield 'whitespace only' => [[" \t\n"], []];
        yield 'mixed blanks and value' => [['', '5', '  ', null], ['5']];
        yield 'string zero survives' => [['', '0', '  '], ['0']];
        yield 'integer zero survives' => [['', 0], [0]];
    }

    #[Test]
    public function numericLookingStringsRemainDistinct(): void
    {
        $set = ValueSet::fromContext($this->context(['007', '7', '007']));

        self::assertSame(['007', '7'], $set->values);
        self::assertTrue($set->isMulti());
    }

    #[Test]
    public function nullValueIsEmpty(): void
    {
        self::assertTrue(ValueSet::fromContext($this->context(null))->isEmpty());
    }

    #[Test]
    public function nullEntriesAreDropped(): void
    {
        $set = ValueSet::fromContext($this->context(['5', null]));

        self::assertSame(['5'], $set->values);
    }

    #[Test]
    public function nestedValueIsRejected(): void
    {
        $this->expectException(FilterValueException::class);
        $this->expectExceptionMessage('Filter "color_id" does not accept nested values.');

        ValueSet::fromContext($this->context([['5']]));
    }

    /** @param array<array-key, string> $value */
    #[Test]
    #[DataProvider('nonListValues')]
    public function nonListArraysAreRejected(array $value): void
    {
        $this->expectException(FilterValueException::class);
        $this->expectExceptionMessage('Filter "color_id" expects a scalar or a list of values.');

        ValueSet::fromContext($this->context($value));
    }

    /** @return iterable<string, array{array<array-key, string>}> */
    public static function nonListValues(): iterable
    {
        yield 'operator map' => [['gte' => '5']];
        yield 'sparse numeric keys' => [[0 => '5', 2 => '9']];
        yield 'mixed keys' => [[0 => '5', 'gte' => '9']];
    }

    #[Test]
    public function separatorOptionSplitsStringValues(): void
    {
        $set = ValueSet::fromContext($this->context('5, 9', ['separator' => ',']));

        self::assertSame(['5', '9'], $set->values);
        self::assertTrue($set->isMulti());
    }

    #[Test]
    public function separatorIsIgnoredWithoutTheOption(): void
    {
        $set = ValueSet::fromContext($this->context('5,9'));

        self::assertSame(['5,9'], $set->values);
    }

    #[Test]
    public function negateOptionIsExposed(): void
    {
        self::assertTrue(ValueSet::fromContext($this->context('5', ['negate' => true]))->negate);
    }

    #[Test]
    public function exceedingTheDefaultValueLimitIsRejected(): void
    {
        $this->expectException(FilterValueException::class);
        $this->expectExceptionMessage('accepts at most 100 values, 101 given');

        ValueSet::fromContext($this->context(range(1, 101)));
    }

    #[Test]
    public function theValueLimitIsConfigurable(): void
    {
        $this->expectException(FilterValueException::class);
        $this->expectExceptionMessage('accepts at most 2 values, 3 given');

        ValueSet::fromContext($this->context(['1', '2', '3'], ['maxValues' => 2]));
    }

    #[Test]
    public function theValueLimitCanBeDisabled(): void
    {
        $set = ValueSet::fromContext($this->context(range(1, 101), ['maxValues' => 0]));

        self::assertCount(101, $set->values);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function context(mixed $value, array $options = []): FilterContext
    {
        return new FilterContext(value: $value, table: 'tx_test', column: 'color_id', options: $options);
    }
}
