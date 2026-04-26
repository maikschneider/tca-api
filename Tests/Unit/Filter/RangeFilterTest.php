<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use Doctrine\DBAL\ParameterType;
use MaikSchneider\TcaApi\Filter\RangeFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Unit tests for RangeFilter.
 *
 * The filter is exercised through a mocked QueryBuilder; we capture the
 * arguments passed to createNamedParameter() to verify that the correct
 * scalar value and DBAL ParameterType are produced for ints, floats,
 * numeric strings and dates — including overrides via the `type` option.
 */
final class RangeFilterTest extends TestCase
{
    /** @var list<array{value: mixed, type: mixed}> */
    private array $captured = [];

    /** @var QueryBuilder&\PHPUnit\Framework\MockObject\MockObject */
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $this->captured = [];

        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('gte')->willReturnCallback(static fn (string $c, string $p) => "$c >= $p");
        $expr->method('lte')->willReturnCallback(static fn (string $c, string $p) => "$c <= $p");
        $expr->method('gt')->willReturnCallback(static fn (string $c, string $p) => "$c > $p");
        $expr->method('lt')->willReturnCallback(static fn (string $c, string $p) => "$c < $p");

        $this->qb = $this->createMock(QueryBuilder::class);
        $this->qb->method('expr')->willReturn($expr);
        $this->qb->method('createNamedParameter')->willReturnCallback(
            function (mixed $value, mixed $type = ParameterType::STRING): string {
                $this->captured[] = ['value' => $value, 'type' => $type];
                return ':p' . count($this->captured);
            },
        );
    }

    // ── autodetection ────────────────────────────────────────────────────

    #[Test]
    public function nativeIntIsBoundAsInteger(): void
    {
        $filter = new RangeFilter();

        $filter->apply($this->qb, 'tstamp', ['value' => ['gte' => 1700000000]]);

        self::assertCount(1, $this->captured);
        self::assertSame(1700000000, $this->captured[0]['value']);
        self::assertSame(ParameterType::INTEGER, $this->captured[0]['type']);
    }

    #[Test]
    public function digitOnlyStringIsBoundAsInteger(): void
    {
        $filter = new RangeFilter();

        $filter->apply($this->qb, 'tstamp', ['value' => ['gte' => '1700000000']]);

        self::assertSame(1700000000, $this->captured[0]['value']);
        self::assertSame(ParameterType::INTEGER, $this->captured[0]['type']);
    }

    #[Test]
    public function nativeFloatIsBoundAsString(): void
    {
        $filter = new RangeFilter();

        $filter->apply($this->qb, 'price', ['value' => ['lte' => 99.99]]);

        self::assertSame('99.99', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function decimalStringIsBoundAsString(): void
    {
        $filter = new RangeFilter();

        $filter->apply($this->qb, 'price', ['value' => ['lte' => '99.99']]);

        self::assertSame('99.99', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function dateStringIsBoundAsString(): void
    {
        $filter = new RangeFilter();

        $filter->apply($this->qb, 'created_at', ['value' => ['gte' => '2024-01-01']]);

        self::assertSame('2024-01-01', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function negativeNumericStringIsBoundAsString(): void
    {
        $filter = new RangeFilter();

        $filter->apply($this->qb, 'amount', ['value' => ['gt' => '-5']]);

        // ctype_digit returns false for negative numbers, so it falls through
        // to the numeric-string branch — DBAL is happy with STRING here.
        self::assertSame('-5', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    // ── explicit type hint ───────────────────────────────────────────────

    #[Test]
    public function explicitIntTypeForcesIntegerCast(): void
    {
        $filter = new RangeFilter();

        $filter->apply($this->qb, 'price', [
            'value' => ['gte' => '99.9'],
            'type'  => 'int',
        ]);

        self::assertSame(99, $this->captured[0]['value']);
        self::assertSame(ParameterType::INTEGER, $this->captured[0]['type']);
    }

    #[Test]
    public function explicitFloatTypeNormalizesValue(): void
    {
        $filter = new RangeFilter();

        $filter->apply($this->qb, 'price', [
            'value' => ['lte' => '99.99'],
            'type'  => 'float',
        ]);

        self::assertSame('99.99', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function explicitStringTypePreservesDigitString(): void
    {
        $filter = new RangeFilter();

        $filter->apply($this->qb, 'code', [
            'value' => ['gte' => '0042'],
            'type'  => 'string',
        ]);

        // Without the type hint a digit-only string would become an int and
        // lose its leading zeros — the explicit hint is the escape hatch.
        self::assertSame('0042', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function explicitDateTypeForcesStringEvenForNumericInput(): void
    {
        $filter = new RangeFilter();

        $filter->apply($this->qb, 'created_at', [
            'value' => ['gte' => '20240101'],
            'type'  => 'date',
        ]);

        self::assertSame('20240101', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    // ── operator wiring ──────────────────────────────────────────────────

    #[Test]
    public function allFourOperatorsApplyAndConditions(): void
    {
        $filter = new RangeFilter();

        $this->qb->expects(self::exactly(4))->method('andWhere');

        $filter->apply($this->qb, 'col', [
            'value' => ['gte' => 1, 'lte' => 10, 'gt' => 0, 'lt' => 11],
        ]);

        self::assertCount(4, $this->captured);
    }

    #[Test]
    public function unknownOperatorIsIgnored(): void
    {
        $filter = new RangeFilter();

        $this->qb->expects(self::once())->method('andWhere');

        $filter->apply($this->qb, 'col', [
            'value' => ['gte' => 1, 'between' => [1, 10]],
        ]);
    }

    // ── guards ───────────────────────────────────────────────────────────

    #[Test]
    public function nonArrayValueIsSilentlyIgnored(): void
    {
        $filter = new RangeFilter();

        $this->qb->expects(self::never())->method('andWhere');

        $filter->apply($this->qb, 'col', ['value' => '100']);

        self::assertSame([], $this->captured);
    }

    #[Test]
    public function emptyOperatorMapIsNoOp(): void
    {
        $filter = new RangeFilter();

        $this->qb->expects(self::never())->method('andWhere');

        $filter->apply($this->qb, 'col', ['value' => []]);
    }

    #[Test]
    public function nonStringTypeOptionIsIgnoredAndAutodetectionApplies(): void
    {
        $filter = new RangeFilter();

        $filter->apply($this->qb, 'col', [
            'value' => ['gte' => 5],
            'type'  => 42,
        ]);

        // Falls through to autodetection: int input → INTEGER param.
        self::assertSame(5, $this->captured[0]['value']);
        self::assertSame(ParameterType::INTEGER, $this->captured[0]['type']);
    }
}
