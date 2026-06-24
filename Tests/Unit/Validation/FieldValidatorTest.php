<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Validation;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Tests\Unit\Validation\Fixtures\RecordingValidator;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;
use MaikSchneider\TcaApi\Validation\FieldValidator;
use MaikSchneider\TcaApi\Validation\ValidationContext;
use MaikSchneider\TcaApi\Validation\Violation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldValidatorTest extends TestCase
{
    private FieldValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FieldValidator();
        $GLOBALS['TCA'] = [];
        $this->resetTcaColumnDiscoveryCache();
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = [];
        $this->resetTcaColumnDiscoveryCache();
        parent::tearDown();
    }

    private function resetTcaColumnDiscoveryCache(): void
    {
        $cache = new \ReflectionProperty(TcaColumnDiscovery::class, 'columnNameCache');
        $cache->setValue(null, []);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function explicitDef(array $columns = [], array $security = []): ApiDefinition
    {
        $raw = [
            'general' => ['table' => 'tx_test', 'resourceName' => 'tests', 'resourceType' => 'Test'],
            'columns' => $columns,
        ];
        if ($security) {
            $raw['security'] = $security;
        }
        return ApiDefinition::fromArray($raw);
    }

    private static function defaultModeDef(array $columns = []): ApiDefinition
    {
        // Default mode = no column has a 'groups' key
        $raw = [
            'general' => ['table' => 'tx_test', 'resourceName' => 'tests', 'resourceType' => 'Test'],
            'columns' => $columns,
        ];
        return ApiDefinition::fromArray($raw);
    }

    // ── Default mode (isExplicitMode = false) ─────────────────────────────────

    #[Test]
    public function defaultModeWithNoColumnsReturnsNoViolations(): void
    {
        $def = self::defaultModeDef();

        $violations = $this->validator->validate(['title' => 'hello'], $def);

        self::assertSame([], $violations);
    }

    #[Test]
    public function defaultModeRunsDeclaredValidatorsOnProvidedField(): void
    {
        $def = self::defaultModeDef([
            'title' => ['validators' => [['type' => 'maxLength', 'max' => 5]]],
        ]);

        $violations = $this->validator->validate(['title' => 'too long string'], $def);

        self::assertCount(1, $violations);
        self::assertSame('title', $violations[0]['propertyPath']);
        self::assertSame('MAX_LENGTH', $violations[0]['code']);
    }

    #[Test]
    public function defaultModeSkipsFieldsNotProvidedInBody(): void
    {
        $def = self::defaultModeDef([
            'title' => ['validators' => [['type' => 'maxLength', 'max' => 5]]],
        ]);

        // 'title' not in body → no violation even though validator is configured
        $violations = $this->validator->validate([], $def);

        self::assertSame([], $violations);
    }

    #[Test]
    public function defaultModeWithPartialTrueSkipsAbsentFields(): void
    {
        $def = self::defaultModeDef([
            'title' => ['validators' => [['type' => 'maxLength', 'max' => 5]]],
        ]);

        // partial=true, 'title' absent → skip
        $violations = $this->validator->validate([], $def, partial: true);

        self::assertSame([], $violations);
    }

    #[Test]
    public function defaultModeWithPartialTrueValidatesProvidedFields(): void
    {
        $def = self::defaultModeDef([
            'title' => ['validators' => [['type' => 'maxLength', 'max' => 5]]],
        ]);

        // partial=true, 'title' IS present → validate it
        $violations = $this->validator->validate(['title' => 'too long'], $def, partial: true);

        self::assertCount(1, $violations);
        self::assertSame('MAX_LENGTH', $violations[0]['code']);
    }

    #[Test]
    public function defaultModeEnforcesRequiredOnMissingDeclaredField(): void
    {
        $def = self::defaultModeDef([
            'title' => ['required' => true],
        ]);

        $violations = $this->validator->validate([], $def);

        self::assertCount(1, $violations);
        self::assertSame('REQUIRED', $violations[0]['code']);
        self::assertSame('title', $violations[0]['propertyPath']);
    }

    #[Test]
    public function defaultModeEnforcesRequiredOnEmptyStringForDeclaredField(): void
    {
        $def = self::defaultModeDef([
            'title' => ['required' => true],
        ]);

        $violations = $this->validator->validate(['title' => ''], $def);

        self::assertSame('REQUIRED', $violations[0]['code']);
    }

    #[Test]
    public function defaultModeEnforcesRequiredOnNullValueForDeclaredField(): void
    {
        $def = self::defaultModeDef([
            'title' => ['required' => true],
        ]);

        $violations = $this->validator->validate(['title' => null], $def);

        self::assertSame('REQUIRED', $violations[0]['code']);
    }

    #[Test]
    public function defaultModePartialSkipsRequiredCheckWhenFieldAbsent(): void
    {
        $def = self::defaultModeDef([
            'title' => ['required' => true],
        ]);

        $violations = $this->validator->validate([], $def, partial: true);

        self::assertSame([], $violations);
    }

    #[Test]
    public function defaultModeEnforcesRequiredOnUndeclaredTcaColumn(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl'    => ['delete' => 'deleted'],
            'columns' => [
                'title' => ['config' => ['type' => 'input', 'required' => true, 'max' => 255]],
            ],
        ];
        $this->resetTcaColumnDiscoveryCache();

        // No 'columns' key → undeclared column 'title' walks the Pass-2 loop.
        $def = self::defaultModeDef();

        $violations = $this->validator->validate([], $def);

        self::assertCount(1, $violations);
        self::assertSame('REQUIRED', $violations[0]['code']);
        self::assertSame('title', $violations[0]['propertyPath']);
    }

    #[Test]
    public function defaultModePartialSkipsRequiredCheckForUndeclaredTcaColumn(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl'    => ['delete' => 'deleted'],
            'columns' => [
                'title' => ['config' => ['type' => 'input', 'required' => true]],
            ],
        ];
        $this->resetTcaColumnDiscoveryCache();

        $def = self::defaultModeDef();

        $violations = $this->validator->validate([], $def, partial: true);

        self::assertSame([], $violations);
    }

    // ── Explicit mode (isExplicitMode = true) ─────────────────────────────────

    #[Test]
    public function explicitModeEnforcesRequiredOnMissingField(): void
    {
        $def = self::explicitDef([
            'title' => ['groups' => ['create', 'update'], 'required' => true],
        ]);

        $violations = $this->validator->validate([], $def);

        self::assertCount(1, $violations);
        self::assertSame('REQUIRED', $violations[0]['code']);
        self::assertSame('title', $violations[0]['propertyPath']);
    }

    #[Test]
    public function explicitModeEnforcesRequiredOnEmptyString(): void
    {
        $def = self::explicitDef([
            'title' => ['groups' => ['create', 'update'], 'required' => true],
        ]);

        $violations = $this->validator->validate(['title' => ''], $def);

        self::assertSame('REQUIRED', $violations[0]['code']);
    }

    #[Test]
    public function explicitModeEnforcesRequiredOnNullValue(): void
    {
        $def = self::explicitDef([
            'title' => ['groups' => ['create', 'update'], 'required' => true],
        ]);

        $violations = $this->validator->validate(['title' => null], $def);

        self::assertSame('REQUIRED', $violations[0]['code']);
    }

    #[Test]
    public function explicitModeSkipsNonWritableColumns(): void
    {
        // groups = ['list'] only → isWritable() = false → skipped
        $def = self::explicitDef([
            'title' => ['groups' => ['list'], 'required' => true],
        ]);

        $violations = $this->validator->validate([], $def);

        self::assertSame([], $violations);
    }

    #[Test]
    public function explicitModePartialSkipsAbsentWritableColumns(): void
    {
        $def = self::explicitDef([
            'title' => ['groups' => ['create', 'update'], 'required' => true],
        ]);

        // partial=true + field absent → skip even though required
        $violations = $this->validator->validate([], $def, partial: true);

        self::assertSame([], $violations);
    }

    // ── Validator: maxLength ──────────────────────────────────────────────────

    #[Test]
    public function maxLengthPassesWhenWithinLimit(): void
    {
        $def = self::explicitDef([
            'title' => ['groups' => ['create'], 'validators' => [['type' => 'maxLength', 'max' => 10]]],
        ]);

        $violations = $this->validator->validate(['title' => 'hello'], $def);

        self::assertSame([], $violations);
    }

    #[Test]
    public function maxLengthFailsWhenExceedingLimit(): void
    {
        $def = self::explicitDef([
            'title' => ['groups' => ['create'], 'validators' => [['type' => 'maxLength', 'max' => 5]]],
        ]);

        $violations = $this->validator->validate(['title' => 'toolong'], $def);

        self::assertCount(1, $violations);
        self::assertSame('MAX_LENGTH', $violations[0]['code']);
    }

    // ── Validator: minLength ──────────────────────────────────────────────────

    #[Test]
    public function minLengthPassesWhenAtLimit(): void
    {
        $def = self::explicitDef([
            'body' => ['groups' => ['create'], 'validators' => [['type' => 'minLength', 'min' => 3]]],
        ]);

        $violations = $this->validator->validate(['body' => 'abc'], $def);

        self::assertSame([], $violations);
    }

    #[Test]
    public function minLengthFailsWhenBelowLimit(): void
    {
        $def = self::explicitDef([
            'body' => ['groups' => ['create'], 'validators' => [['type' => 'minLength', 'min' => 5]]],
        ]);

        $violations = $this->validator->validate(['body' => 'hi'], $def);

        self::assertCount(1, $violations);
        self::assertSame('MIN_LENGTH', $violations[0]['code']);
    }

    // ── Validator: regex ─────────────────────────────────────────────────────

    #[Test]
    public function regexPassesWhenPatternMatches(): void
    {
        $def = self::explicitDef([
            'slug' => ['groups' => ['create'], 'validators' => [['type' => 'regex', 'pattern' => '/^[a-z]+$/']]],
        ]);

        $violations = $this->validator->validate(['slug' => 'hello'], $def);

        self::assertSame([], $violations);
    }

    #[Test]
    public function regexFailsWhenPatternDoesNotMatch(): void
    {
        $def = self::explicitDef([
            'slug' => ['groups' => ['create'], 'validators' => [['type' => 'regex', 'pattern' => '/^[a-z]+$/']]],
        ]);

        $violations = $this->validator->validate(['slug' => 'HELLO123'], $def);

        self::assertCount(1, $violations);
        self::assertSame('REGEX', $violations[0]['code']);
    }

    #[Test]
    public function regexWithInvalidPatternThrowsAtDefinitionTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid regex pattern');
        self::explicitDef([
            'slug' => ['groups' => ['create'], 'validators' => [['type' => 'regex', 'pattern' => 'not-a-valid-regex']]],
        ]);
    }

    #[Test]
    public function regexWithMissingPatternThrowsAtDefinitionTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('validators[0].pattern');
        self::explicitDef([
            'slug' => ['groups' => ['create'], 'validators' => [['type' => 'regex']]],
        ]);
    }

    // ── Multiple validators on one field ──────────────────────────────────────

    #[Test]
    public function multipleValidatorsAllRunOnProvidedField(): void
    {
        $def = self::explicitDef([
            'slug' => [
                'groups' => ['create'],
                'validators' => [
                    ['type' => 'minLength', 'min' => 10],
                    ['type' => 'maxLength', 'max' => 3],
                ],
            ],
        ]);

        $violations = $this->validator->validate(['slug' => 'abcde'], $def);

        self::assertCount(2, $violations);
        $codes = array_column($violations, 'code');
        self::assertContains('MIN_LENGTH', $codes);
        self::assertContains('MAX_LENGTH', $codes);
    }

    // ── Mixed partial + required + validators ────────────────────────────────

    #[Test]
    public function partialModeWithRequiredFieldProvidedStillRunsValidators(): void
    {
        $def = self::explicitDef([
            'title' => [
                'groups' => ['create', 'update'],
                'required' => true,
                'validators' => [['type' => 'maxLength', 'max' => 5]],
            ],
        ]);

        // partial=true, field IS provided but fails validator
        $violations = $this->validator->validate(['title' => 'toolong'], $def, partial: true);

        self::assertCount(1, $violations);
        self::assertSame('MAX_LENGTH', $violations[0]['code']);
    }

    #[Test]
    public function partialModeSkipsAbsentRequiredFieldButValidatesPresent(): void
    {
        $def = self::explicitDef([
            'title' => ['groups' => ['create', 'update'], 'required' => true],
            'body'  => [
                'groups' => ['create', 'update'],
                'validators' => [['type' => 'minLength', 'min' => 5]],
            ],
        ]);

        // partial=true: 'title' absent → skip, 'body' present but too short → violation
        $violations = $this->validator->validate(['body' => 'hi'], $def, partial: true);

        self::assertCount(1, $violations);
        self::assertSame('MIN_LENGTH', $violations[0]['code']);
        self::assertSame('body', $violations[0]['propertyPath']);
    }

    // ── Regex: invalid pattern ───────────────────────────────────────────────

    #[Test]
    public function regexWithNestedQuantifiersDoesNotThrow(): void
    {
        // Intentionally uses a nested-quantifier pattern to verify the validator
        // handles complex (but valid) regex without throwing or hanging.
        $def = self::explicitDef([
            'slug' => ['groups' => ['create'], 'validators' => [['type' => 'regex', 'pattern' => '/^[a-z]+$/']]],
        ]);

        $violations = $this->validator->validate(['slug' => 'aaaaaa'], $def);

        self::assertSame([], $violations);
    }

    // ── Validator: minValue ───────────────────────────────────────────────────

    #[Test]
    public function minValuePassesWhenAboveLimit(): void
    {
        $def = self::explicitDef([
            'amount' => ['groups' => ['create'], 'validators' => [['type' => 'minValue', 'min' => 10]]],
        ]);

        $violations = $this->validator->validate(['amount' => 15], $def);

        self::assertSame([], $violations);
    }

    #[Test]
    public function minValueFailsWhenBelowLimit(): void
    {
        $def = self::explicitDef([
            'amount' => ['groups' => ['create'], 'validators' => [['type' => 'minValue', 'min' => 10]]],
        ]);

        $violations = $this->validator->validate(['amount' => 5], $def);

        self::assertCount(1, $violations);
        self::assertSame('MIN_VALUE', $violations[0]['code']);
        self::assertSame('amount', $violations[0]['propertyPath']);
    }

    #[Test]
    public function minValueSkipsNonNumericValue(): void
    {
        $def = self::explicitDef([
            'amount' => ['groups' => ['create'], 'validators' => [['type' => 'minValue', 'min' => 10]]],
        ]);

        $violations = $this->validator->validate(['amount' => 'not-a-number'], $def);

        self::assertSame([], $violations);
    }

    // ── Validator: maxValue ───────────────────────────────────────────────────

    #[Test]
    public function maxValuePassesWhenBelowLimit(): void
    {
        $def = self::explicitDef([
            'amount' => ['groups' => ['create'], 'validators' => [['type' => 'maxValue', 'max' => 100]]],
        ]);

        $violations = $this->validator->validate(['amount' => 50], $def);

        self::assertSame([], $violations);
    }

    #[Test]
    public function maxValueFailsWhenAboveLimit(): void
    {
        $def = self::explicitDef([
            'amount' => ['groups' => ['create'], 'validators' => [['type' => 'maxValue', 'max' => 100]]],
        ]);

        $violations = $this->validator->validate(['amount' => 150], $def);

        self::assertCount(1, $violations);
        self::assertSame('MAX_VALUE', $violations[0]['code']);
        self::assertSame('amount', $violations[0]['propertyPath']);
    }

    #[Test]
    public function maxValueSkipsNonNumericValue(): void
    {
        $def = self::explicitDef([
            'amount' => ['groups' => ['create'], 'validators' => [['type' => 'maxValue', 'max' => 100]]],
        ]);

        $violations = $this->validator->validate(['amount' => 'not-a-number'], $def);

        self::assertSame([], $violations);
    }

    // ── Validator: minItems ───────────────────────────────────────────────────

    #[Test]
    public function minItemsPassesAtThreshold(): void
    {
        $def = self::explicitDef([
            'tags' => ['groups' => ['create'], 'validators' => [['type' => 'minItems', 'min' => 2]]],
        ]);

        $violations = $this->validator->validate(['tags' => ['a', 'b']], $def);

        self::assertSame([], $violations);
    }

    #[Test]
    public function minItemsFailsOnEmptyArray(): void
    {
        $def = self::explicitDef([
            'tags' => ['groups' => ['create'], 'validators' => [['type' => 'minItems', 'min' => 2]]],
        ]);

        $violations = $this->validator->validate(['tags' => []], $def);

        self::assertCount(1, $violations);
        self::assertSame('MIN_ITEMS', $violations[0]['code']);
        self::assertSame('tags', $violations[0]['propertyPath']);
    }

    #[Test]
    public function minItemsSkipsNonArrayValue(): void
    {
        $def = self::explicitDef([
            'tags' => ['groups' => ['create'], 'validators' => [['type' => 'minItems', 'min' => 2]]],
        ]);

        $violations = $this->validator->validate(['tags' => 'not-an-array'], $def);

        self::assertSame([], $violations);
    }

    // ── Validator: maxItems ───────────────────────────────────────────────────

    #[Test]
    public function maxItemsPassesAtThreshold(): void
    {
        $def = self::explicitDef([
            'tags' => ['groups' => ['create'], 'validators' => [['type' => 'maxItems', 'max' => 3]]],
        ]);

        $violations = $this->validator->validate(['tags' => ['a', 'b', 'c']], $def);

        self::assertSame([], $violations);
    }

    #[Test]
    public function maxItemsFailsAboveLimit(): void
    {
        $def = self::explicitDef([
            'tags' => ['groups' => ['create'], 'validators' => [['type' => 'maxItems', 'max' => 2]]],
        ]);

        $violations = $this->validator->validate(['tags' => ['a', 'b', 'c']], $def);

        self::assertCount(1, $violations);
        self::assertSame('MAX_ITEMS', $violations[0]['code']);
        self::assertSame('tags', $violations[0]['propertyPath']);
    }

    #[Test]
    public function maxItemsSkipsNonArrayValue(): void
    {
        $def = self::explicitDef([
            'tags' => ['groups' => ['create'], 'validators' => [['type' => 'maxItems', 'max' => 2]]],
        ]);

        $violations = $this->validator->validate(['tags' => 'not-an-array'], $def);

        self::assertSame([], $violations);
    }

    // ── Custom validators (#147) ──────────────────────────────────────────────

    #[Test]
    public function customValidatorReceivesFullContext(): void
    {
        $recorder = new RecordingValidator();
        $validator = new FieldValidator([$recorder]);

        $def = self::explicitDef([
            'iban' => [
                'groups'     => ['create', 'update'],
                'validators' => [['type' => RecordingValidator::class, 'options' => ['country' => 'DE']]],
            ],
        ]);

        $validator->validate(['iban' => 'DE123', 'holder' => 'Ada'], $def, partial: true);

        self::assertInstanceOf(ValidationContext::class, $recorder->received);
        self::assertSame('DE123', $recorder->received->value);
        self::assertSame('iban', $recorder->received->column);
        self::assertSame('tx_test', $recorder->received->table);
        self::assertSame(['country' => 'DE'], $recorder->received->options);
        self::assertSame(['iban' => 'DE123', 'holder' => 'Ada'], $recorder->received->body);
        self::assertTrue($recorder->received->partial);
        self::assertSame($def, $recorder->received->resourceConfig);
    }

    #[Test]
    public function customValidatorViolationsAreMergedWithColumnAsDefaultPath(): void
    {
        $recorder = new RecordingValidator();
        $recorder->toReturn = [new Violation('Invalid IBAN.', 'IBAN')];
        $validator = new FieldValidator([$recorder]);

        $def = self::explicitDef([
            'iban' => [
                'groups'     => ['create'],
                'validators' => [['type' => RecordingValidator::class]],
            ],
        ]);

        $violations = $validator->validate(['iban' => 'bad'], $def);

        self::assertSame(
            [['propertyPath' => 'iban', 'message' => 'Invalid IBAN.', 'code' => 'IBAN']],
            $violations,
        );
    }

    #[Test]
    public function customValidatorMayReturnMultipleViolationsAndOverridePath(): void
    {
        $recorder = new RecordingValidator();
        $recorder->toReturn = [
            new Violation('First problem.', 'A'),
            new Violation('Cross-field problem.', 'B', propertyPath: 'holder'),
        ];
        $validator = new FieldValidator([$recorder]);

        $def = self::explicitDef([
            'iban' => [
                'groups'     => ['create'],
                'validators' => [['type' => RecordingValidator::class]],
            ],
        ]);

        $violations = $validator->validate(['iban' => 'bad'], $def);

        self::assertCount(2, $violations);
        self::assertSame('iban', $violations[0]['propertyPath']);
        self::assertSame('holder', $violations[1]['propertyPath']);
        self::assertSame('B', $violations[1]['code']);
    }

    #[Test]
    public function builtInAndCustomValidatorsRunTogether(): void
    {
        $recorder = new RecordingValidator();
        $recorder->toReturn = [new Violation('Invalid IBAN.', 'IBAN')];
        $validator = new FieldValidator([$recorder]);

        $def = self::explicitDef([
            'iban' => [
                'groups'     => ['create'],
                'validators' => [
                    ['type' => 'maxLength', 'max' => 3],
                    ['type' => RecordingValidator::class],
                ],
            ],
        ]);

        $violations = $validator->validate(['iban' => 'toolong'], $def);

        $codes = array_column($violations, 'code');
        self::assertContains('MAX_LENGTH', $codes);
        self::assertContains('IBAN', $codes);
    }

    #[Test]
    public function unregisteredCustomValidatorThrows(): void
    {
        // RecordingValidator passes boot validation but is not provided to the
        // FieldValidator instance, so resolution must fail loudly, not skip.
        $validator = new FieldValidator([]);

        $def = self::explicitDef([
            'iban' => [
                'groups'     => ['create'],
                'validators' => [['type' => RecordingValidator::class]],
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No validator registered');
        $validator->validate(['iban' => 'x'], $def);
    }
}
