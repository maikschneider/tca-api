<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Loader;

use MaikSchneider\TcaApi\Loader\TcaValidatorDeriver;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see TcaValidatorDeriver}.
 *
 * The deriver reads $GLOBALS['TCA'] directly, so tests populate that global
 * before each assertion and reset it in tearDown.
 */
final class TcaValidatorDeriverTest extends TestCase
{
    private TcaValidatorDeriver $deriver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deriver = new TcaValidatorDeriver();
        $GLOBALS['TCA'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = [];
        $cache = new \ReflectionProperty(TcaColumnDiscovery::class, 'columnNameCache');
        $cache->setValue(null, []);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $columnTca Full TCA column array (outer, with 'config' key)
     */
    private function buildGlobalTca(string $table, string $column, array $columnTca): void
    {
        $GLOBALS['TCA'][$table]['columns'][$column] = $columnTca;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validatorsFor(array $rawConfig, string $columnName): array
    {
        return $rawConfig['columns'][$columnName]['validators'] ?? [];
    }

    // ── input / text → maxLength ──────────────────────────────────────────────

    #[Test]
    public function inputWithMaxDerivesMaxLengthValidator(): void
    {
        $this->buildGlobalTca('tx_test', 'title', ['config' => ['type' => 'input', 'max' => 255]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['title' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        $validators = $this->validatorsFor($result, 'title');
        self::assertCount(1, $validators);
        self::assertSame('maxLength', $validators[0]['type']);
        self::assertSame(255, $validators[0]['max']);
    }

    #[Test]
    public function textWithMaxDerivesMaxLengthValidator(): void
    {
        $this->buildGlobalTca('tx_test', 'description', ['config' => ['type' => 'text', 'max' => 100]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['description' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        $validators = $this->validatorsFor($result, 'description');
        self::assertCount(1, $validators);
        self::assertSame('maxLength', $validators[0]['type']);
        self::assertSame(100, $validators[0]['max']);
    }

    // ── number → minValue / maxValue ──────────────────────────────────────────

    #[Test]
    public function numberWithRangeLowerDerivesMinValueValidator(): void
    {
        $this->buildGlobalTca('tx_test', 'amount', ['config' => ['type' => 'number', 'range' => ['lower' => 0]]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['amount' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        $validators = $this->validatorsFor($result, 'amount');
        self::assertCount(1, $validators);
        self::assertSame('minValue', $validators[0]['type']);
        self::assertSame(0, $validators[0]['min']);
    }

    #[Test]
    public function numberWithRangeUpperDerivesMaxValueValidator(): void
    {
        $this->buildGlobalTca('tx_test', 'amount', ['config' => ['type' => 'number', 'range' => ['upper' => 100]]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['amount' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        $validators = $this->validatorsFor($result, 'amount');
        self::assertCount(1, $validators);
        self::assertSame('maxValue', $validators[0]['type']);
        self::assertSame(100, $validators[0]['max']);
    }

    #[Test]
    public function numberWithLowerAndUpperDerivesBothValidators(): void
    {
        $this->buildGlobalTca('tx_test', 'amount', ['config' => ['type' => 'number', 'range' => ['lower' => 5, 'upper' => 50]]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['amount' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        $validators = $this->validatorsFor($result, 'amount');
        self::assertCount(2, $validators);
        $types = array_column($validators, 'type');
        self::assertContains('minValue', $types);
        self::assertContains('maxValue', $types);
    }

    // ── file / group / inline / category → maxItems / minItems ───────────────

    #[Test]
    public function fileWithMaxItemsDerivesMaxItemsValidator(): void
    {
        $this->buildGlobalTca('tx_test', 'avatar', ['config' => ['type' => 'file', 'maxitems' => 1]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['avatar' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        $validators = $this->validatorsFor($result, 'avatar');
        self::assertCount(1, $validators);
        self::assertSame('maxItems', $validators[0]['type']);
        self::assertSame(1, $validators[0]['max']);
    }

    #[Test]
    public function fileWithMinItemsZeroSkipsMinItemsValidator(): void
    {
        $this->buildGlobalTca('tx_test', 'avatar', ['config' => ['type' => 'file', 'minitems' => 0]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['avatar' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        $types = array_column($this->validatorsFor($result, 'avatar'), 'type');
        self::assertNotContains('minItems', $types);
    }

    #[Test]
    public function fileWithMinItemsOneDerivesMinItemsValidator(): void
    {
        $this->buildGlobalTca('tx_test', 'avatar', ['config' => ['type' => 'file', 'minitems' => 1]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['avatar' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        $validators = $this->validatorsFor($result, 'avatar');
        self::assertCount(1, $validators);
        self::assertSame('minItems', $validators[0]['type']);
        self::assertSame(1, $validators[0]['min']);
    }

    #[Test]
    public function groupWithMaxItemsDerivesMaxItemsValidator(): void
    {
        $this->buildGlobalTca('tx_test', 'related', ['config' => ['type' => 'group', 'maxitems' => 3]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['related' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        $validators = $this->validatorsFor($result, 'related');
        self::assertCount(1, $validators);
        self::assertSame('maxItems', $validators[0]['type']);
        self::assertSame(3, $validators[0]['max']);
    }

    #[Test]
    public function inlineWithMaxItemsDerivesMaxItemsValidator(): void
    {
        $this->buildGlobalTca('tx_test', 'children', ['config' => ['type' => 'inline', 'maxitems' => 10]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['children' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        $validators = $this->validatorsFor($result, 'children');
        self::assertCount(1, $validators);
        self::assertSame('maxItems', $validators[0]['type']);
        self::assertSame(10, $validators[0]['max']);
    }

    #[Test]
    public function categoryWithMaxItemsDerivesMaxItemsValidator(): void
    {
        $this->buildGlobalTca('tx_test', 'tags', ['config' => ['type' => 'category', 'maxitems' => 5]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['tags' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        $validators = $this->validatorsFor($result, 'tags');
        self::assertCount(1, $validators);
        self::assertSame('maxItems', $validators[0]['type']);
        self::assertSame(5, $validators[0]['max']);
    }

    // ── gap-fill semantics ────────────────────────────────────────────────────

    #[Test]
    public function explicitMaxLengthIsNotOverriddenByTcaDerivation(): void
    {
        $this->buildGlobalTca('tx_test', 'title', ['config' => ['type' => 'input', 'max' => 255]]);

        $raw = [
            'general' => ['table' => 'tx_test'],
            'columns' => [
                'title' => [
                    'validators' => [
                        ['type' => 'maxLength', 'max' => 20],
                    ],
                ],
            ],
        ];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        $validators = $this->validatorsFor($result, 'title');
        self::assertCount(1, $validators);
        self::assertSame('maxLength', $validators[0]['type']);
        self::assertSame(20, $validators[0]['max']);
    }

    // ── required derivation ───────────────────────────────────────────────────

    #[Test]
    public function requiredKeyAbsentAndTcaRequiredInjectsRequiredTrue(): void
    {
        $this->buildGlobalTca('tx_test', 'title', ['required' => true, 'config' => ['type' => 'input']]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['title' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        self::assertTrue($result['columns']['title']['required']);
    }

    #[Test]
    public function requiredFalseExplicitIsNotOverridden(): void
    {
        $this->buildGlobalTca('tx_test', 'title', ['required' => true, 'config' => ['type' => 'input']]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['title' => ['required' => false]]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        self::assertFalse($result['columns']['title']['required']);
    }

    // ── opt-out and skip cases ────────────────────────────────────────────────

    #[Test]
    public function tcaValidationFalseSkipsAllDerivation(): void
    {
        $this->buildGlobalTca('tx_test', 'title', ['required' => true, 'config' => ['type' => 'input', 'max' => 255]]);

        $raw = [
            'general' => ['table' => 'tx_test'],
            'columns' => ['title' => ['tcaValidation' => false]],
        ];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        self::assertSame([], $this->validatorsFor($result, 'title'));
        self::assertArrayNotHasKey('required', $result['columns']['title']);
    }

    #[Test]
    public function columnNotInTcaIsSkipped(): void
    {
        $this->buildGlobalTca('tx_test', 'known_field', ['config' => ['type' => 'input', 'max' => 255]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['unknown_field' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        self::assertSame([], $this->validatorsFor($result, 'unknown_field'));
    }

    #[Test]
    public function unknownTableReturnsRawConfigUnchanged(): void
    {
        $raw = [
            'general' => ['table' => 'tx_unknown'],
            'columns' => ['title' => ['validators' => []]],
        ];

        $result = $this->deriver->deriveForConfig('tx_unknown', $raw);

        self::assertSame($raw, $result);
    }

    #[Test]
    public function emptyTableStringReturnsRawConfigUnchanged(): void
    {
        $raw = ['general' => ['table' => ''], 'columns' => ['title' => []]];

        $result = $this->deriver->deriveForConfig('', $raw);

        self::assertSame($raw, $result);
    }

    #[Test]
    public function selectTypeDerivesNoValidators(): void
    {
        $this->buildGlobalTca('tx_test', 'color', ['config' => ['type' => 'select', 'foreign_table' => 'tx_test_color', 'maxitems' => 5]]);

        $raw    = ['general' => ['table' => 'tx_test'], 'columns' => ['color' => []]];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        self::assertSame([], $this->validatorsFor($result, 'color'));
    }

    #[Test]
    public function undeclaredColumnReceivesNoStubInjection(): void
    {
        // Default-mode resources (no 'columns' key) used to grow stubs for every
        // exposable TCA column with a derivable constraint. That second pass has
        // been removed — undeclared columns stay out of $config->columns, and
        // consumers derive on-demand via the static helpers below.
        $GLOBALS['TCA']['tx_test']['ctrl']            = [];
        $GLOBALS['TCA']['tx_test']['columns']['name'] = ['required' => true, 'config' => ['type' => 'input', 'max' => 255]];

        $raw    = ['general' => ['table' => 'tx_test']];
        $result = $this->deriver->deriveForConfig('tx_test', $raw);

        self::assertArrayNotHasKey('columns', $result);
    }

    // ── Static helpers used by consumers for undeclared columns ───────────────

    #[Test]
    public function deriveValidatorsForColumnReturnsMaxLengthForInputWithMax(): void
    {
        $this->buildGlobalTca('tx_test', 'title', ['config' => ['type' => 'input', 'max' => 255]]);

        $validators = TcaValidatorDeriver::deriveValidatorsForColumn('tx_test', 'title');

        self::assertCount(1, $validators);
        self::assertSame('maxLength', $validators[0]['type']);
        self::assertSame(255, $validators[0]['max']);
    }

    #[Test]
    public function deriveValidatorsForColumnReturnsRangeValidatorsForNumber(): void
    {
        $this->buildGlobalTca('tx_test', 'amount', ['config' => ['type' => 'number', 'range' => ['lower' => 5, 'upper' => 50]]]);

        $types = array_column(TcaValidatorDeriver::deriveValidatorsForColumn('tx_test', 'amount'), 'type');

        self::assertContains('minValue', $types);
        self::assertContains('maxValue', $types);
    }

    #[Test]
    public function deriveValidatorsForColumnReturnsItemsValidatorsForFile(): void
    {
        $this->buildGlobalTca('tx_test', 'photo', ['config' => ['type' => 'file', 'maxitems' => 1, 'minitems' => 1]]);

        $types = array_column(TcaValidatorDeriver::deriveValidatorsForColumn('tx_test', 'photo'), 'type');

        self::assertContains('maxItems', $types);
        self::assertContains('minItems', $types);
    }

    #[Test]
    public function deriveValidatorsForColumnReturnsEmptyForUnknownTable(): void
    {
        self::assertSame([], TcaValidatorDeriver::deriveValidatorsForColumn('tx_unknown', 'title'));
    }

    #[Test]
    public function deriveValidatorsForColumnReturnsEmptyForUnknownColumn(): void
    {
        $this->buildGlobalTca('tx_test', 'title', ['config' => ['type' => 'input', 'max' => 255]]);

        self::assertSame([], TcaValidatorDeriver::deriveValidatorsForColumn('tx_test', 'missing'));
    }

    #[Test]
    public function deriveValidatorsForColumnReturnsEmptyForSelect(): void
    {
        $this->buildGlobalTca('tx_test', 'color', ['config' => ['type' => 'select', 'foreign_table' => 'tx_test_color', 'maxitems' => 5]]);

        self::assertSame([], TcaValidatorDeriver::deriveValidatorsForColumn('tx_test', 'color'));
    }

    #[Test]
    public function isTcaColumnRequiredReadsColumnLevelFlag(): void
    {
        $this->buildGlobalTca('tx_test', 'title', ['required' => true, 'config' => ['type' => 'input']]);

        self::assertTrue(TcaValidatorDeriver::isTcaColumnRequired('tx_test', 'title'));
    }

    #[Test]
    public function isTcaColumnRequiredReturnsFalseWhenKeyAbsent(): void
    {
        $this->buildGlobalTca('tx_test', 'title', ['config' => ['type' => 'input']]);

        self::assertFalse(TcaValidatorDeriver::isTcaColumnRequired('tx_test', 'title'));
    }

    #[Test]
    public function isTcaColumnRequiredReturnsFalseForUnknownColumn(): void
    {
        self::assertFalse(TcaValidatorDeriver::isTcaColumnRequired('tx_test', 'missing'));
    }
}
