<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use Doctrine\DBAL\ParameterType;
use MaikSchneider\TcaApi\Filter\RangeFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Unit tests for RangeFilter.
 *
 * The filter is exercised through a mocked QueryBuilder; we capture the
 * arguments passed to createNamedParameter() to verify that the correct
 * scalar value and DBAL ParameterType are produced. Type resolution has
 * three layers, tested in order of precedence:
 *
 *   1. Explicit `type` option in the filter config (override).
 *   2. TCA column configuration (`number`/`datetime`/`input eval=int`).
 *   3. Autodetection from the request value.
 */
final class RangeFilterTest extends TestCase
{
    /** @var list<array{value: mixed, type: mixed}> */
    private array $captured = [];

    /** @var QueryBuilder&\PHPUnit\Framework\MockObject\MockObject */
    private QueryBuilder $qb;

    /** @var TcaSchemaFactory&\PHPUnit\Framework\MockObject\MockObject */
    private TcaSchemaFactory $schemaFactory;

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

        // Default: schema factory reports no schema → autodetection only.
        $this->schemaFactory = $this->createMock(TcaSchemaFactory::class);
        $this->schemaFactory->method('has')->willReturn(false);
    }

    private function newFilter(): RangeFilter
    {
        return new RangeFilter($this->schemaFactory);
    }

    /**
     * Wire the schema factory mock to expose a single column with the given
     * raw TCA `config` array.
     */
    private function withTcaColumn(string $table, string $column, array $config): void
    {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getConfiguration')->willReturn($config);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('hasField')->willReturnCallback(static fn (string $c): bool => $c === $column);
        $schema->method('getField')->willReturnCallback(
            static fn (string $c): FieldTypeInterface => $field,
        );

        $this->schemaFactory = $this->createMock(TcaSchemaFactory::class);
        $this->schemaFactory->method('has')->willReturnCallback(static fn (string $t): bool => $t === $table);
        $this->schemaFactory->method('get')->willReturnCallback(static fn (string $t): TcaSchema => $schema);
    }

    // ── value autodetection (no TCA, no explicit type) ───────────────────

    #[Test]
    public function nativeIntIsBoundAsInteger(): void
    {
        $this->newFilter()->apply($this->qb, 'tstamp', ['value' => ['gte' => 1700000000]]);

        self::assertCount(1, $this->captured);
        self::assertSame(1700000000, $this->captured[0]['value']);
        self::assertSame(ParameterType::INTEGER, $this->captured[0]['type']);
    }

    #[Test]
    public function digitOnlyStringIsBoundAsInteger(): void
    {
        $this->newFilter()->apply($this->qb, 'tstamp', ['value' => ['gte' => '1700000000']]);

        self::assertSame(1700000000, $this->captured[0]['value']);
        self::assertSame(ParameterType::INTEGER, $this->captured[0]['type']);
    }

    #[Test]
    public function nativeFloatIsBoundAsString(): void
    {
        $this->newFilter()->apply($this->qb, 'price', ['value' => ['lte' => 99.99]]);

        self::assertSame('99.99', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function decimalStringIsBoundAsString(): void
    {
        $this->newFilter()->apply($this->qb, 'price', ['value' => ['lte' => '99.99']]);

        self::assertSame('99.99', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function dateStringIsBoundAsString(): void
    {
        $this->newFilter()->apply($this->qb, 'created_at', ['value' => ['gte' => '2024-01-01']]);

        self::assertSame('2024-01-01', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function negativeNumericStringIsBoundAsString(): void
    {
        $this->newFilter()->apply($this->qb, 'amount', ['value' => ['gt' => '-5']]);

        // ctype_digit returns false for negatives → falls through to the
        // numeric-string branch and DBAL handles the cast.
        self::assertSame('-5', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    // ── TCA-driven detection ─────────────────────────────────────────────

    #[Test]
    public function tcaNumberDefaultIsTreatedAsInteger(): void
    {
        $this->withTcaColumn('tx_test', 'fe_user_id', ['type' => 'number']);

        $this->newFilter()->apply($this->qb, 'fe_user_id', [
            'value'   => ['gte' => '12.9'],
            '_table'  => 'tx_test',
            '_column' => 'fe_user_id',
        ]);

        self::assertSame(12, $this->captured[0]['value']);
        self::assertSame(ParameterType::INTEGER, $this->captured[0]['type']);
    }

    #[Test]
    public function tcaNumberDecimalIsTreatedAsFloat(): void
    {
        $this->withTcaColumn('tx_test', 'price', [
            'type'   => 'number',
            'format' => 'decimal',
        ]);

        $this->newFilter()->apply($this->qb, 'price', [
            'value'   => ['lte' => '99.99'],
            '_table'  => 'tx_test',
            '_column' => 'price',
        ]);

        self::assertSame('99.99', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function tcaDatetimeWithoutDbTypeIsTreatedAsInteger(): void
    {
        // datetime without dbType is stored as a UNIX timestamp.
        $this->withTcaColumn('tx_test', 'tstamp', ['type' => 'datetime']);

        $this->newFilter()->apply($this->qb, 'tstamp', [
            'value'   => ['gte' => '1704067200'],
            '_table'  => 'tx_test',
            '_column' => 'tstamp',
        ]);

        self::assertSame(1704067200, $this->captured[0]['value']);
        self::assertSame(ParameterType::INTEGER, $this->captured[0]['type']);
    }

    #[Test]
    public function tcaDatetimeWithDbTypeIsTreatedAsString(): void
    {
        // datetime with dbType is a native DATE/DATETIME column.
        $this->withTcaColumn('tx_test', 'created_at', [
            'type'   => 'datetime',
            'dbType' => 'datetime',
        ]);

        $this->newFilter()->apply($this->qb, 'created_at', [
            'value'   => ['gte' => '2024-01-01 00:00:00'],
            '_table'  => 'tx_test',
            '_column' => 'created_at',
        ]);

        self::assertSame('2024-01-01 00:00:00', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function tcaInputWithEvalIntIsTreatedAsInteger(): void
    {
        $this->withTcaColumn('tx_test', 'count', [
            'type' => 'input',
            'eval' => 'trim,int',
        ]);

        $this->newFilter()->apply($this->qb, 'count', [
            'value'   => ['gte' => '42'],
            '_table'  => 'tx_test',
            '_column' => 'count',
        ]);

        self::assertSame(42, $this->captured[0]['value']);
        self::assertSame(ParameterType::INTEGER, $this->captured[0]['type']);
    }

    #[Test]
    public function tcaInputWithoutEvalIntFallsBackToAutodetection(): void
    {
        $this->withTcaColumn('tx_test', 'note', [
            'type' => 'input',
            'eval' => 'trim',
        ]);

        $this->newFilter()->apply($this->qb, 'note', [
            'value'   => ['gte' => '2024-01-01'],
            '_table'  => 'tx_test',
            '_column' => 'note',
        ]);

        self::assertSame('2024-01-01', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function unknownTcaTypeFallsBackToAutodetection(): void
    {
        $this->withTcaColumn('tx_test', 'data', ['type' => 'json']);

        $this->newFilter()->apply($this->qb, 'data', [
            'value'   => ['gte' => 5],
            '_table'  => 'tx_test',
            '_column' => 'data',
        ]);

        self::assertSame(5, $this->captured[0]['value']);
        self::assertSame(ParameterType::INTEGER, $this->captured[0]['type']);
    }

    #[Test]
    public function missingTcaSchemaFallsBackToAutodetection(): void
    {
        // Default mock returns has()=false; just provide the keys.
        $this->newFilter()->apply($this->qb, 'col', [
            'value'   => ['gte' => '2024-01-01'],
            '_table'  => 'tx_unknown',
            '_column' => 'col',
        ]);

        self::assertSame('2024-01-01', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    // ── explicit type hint (overrides TCA) ───────────────────────────────

    #[Test]
    public function explicitTypeOverridesTcaDetection(): void
    {
        // TCA says int, but the explicit `string` option wins.
        $this->withTcaColumn('tx_test', 'sku', ['type' => 'number']);

        $this->newFilter()->apply($this->qb, 'sku', [
            'value'   => ['gte' => '0042'],
            'type'    => 'string',
            '_table'  => 'tx_test',
            '_column' => 'sku',
        ]);

        self::assertSame('0042', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function explicitIntTypeForcesIntegerCast(): void
    {
        $this->newFilter()->apply($this->qb, 'price', [
            'value' => ['gte' => '99.9'],
            'type'  => 'int',
        ]);

        self::assertSame(99, $this->captured[0]['value']);
        self::assertSame(ParameterType::INTEGER, $this->captured[0]['type']);
    }

    #[Test]
    public function explicitFloatTypeNormalizesValue(): void
    {
        $this->newFilter()->apply($this->qb, 'price', [
            'value' => ['lte' => '99.99'],
            'type'  => 'float',
        ]);

        self::assertSame('99.99', $this->captured[0]['value']);
        self::assertSame(ParameterType::STRING, $this->captured[0]['type']);
    }

    #[Test]
    public function explicitDateTypeForcesStringEvenForNumericInput(): void
    {
        $this->newFilter()->apply($this->qb, 'created_at', [
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
        $this->qb->expects(self::exactly(4))->method('andWhere');

        $this->newFilter()->apply($this->qb, 'col', [
            'value' => ['gte' => 1, 'lte' => 10, 'gt' => 0, 'lt' => 11],
        ]);

        self::assertCount(4, $this->captured);
    }

    #[Test]
    public function unknownOperatorIsIgnored(): void
    {
        $this->qb->expects(self::once())->method('andWhere');

        $this->newFilter()->apply($this->qb, 'col', [
            'value' => ['gte' => 1, 'between' => [1, 10]],
        ]);
    }

    // ── guards ───────────────────────────────────────────────────────────

    #[Test]
    public function nonArrayValueIsSilentlyIgnored(): void
    {
        $this->qb->expects(self::never())->method('andWhere');

        $this->newFilter()->apply($this->qb, 'col', ['value' => '100']);

        self::assertSame([], $this->captured);
    }

    #[Test]
    public function emptyOperatorMapIsNoOp(): void
    {
        $this->qb->expects(self::never())->method('andWhere');

        $this->newFilter()->apply($this->qb, 'col', ['value' => []]);
    }

    #[Test]
    public function nonStringTypeOptionIsIgnoredAndAutodetectionApplies(): void
    {
        $this->newFilter()->apply($this->qb, 'col', [
            'value' => ['gte' => 5],
            'type'  => 42,
        ]);

        // Falls through to autodetection: int input → INTEGER param.
        self::assertSame(5, $this->captured[0]['value']);
        self::assertSame(ParameterType::INTEGER, $this->captured[0]['type']);
    }
}
