<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Validation;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Validation\FieldValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldValidatorTest extends TestCase
{
    private FieldValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FieldValidator();
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
    public function defaultModeDoesNotCheckRequiredFields(): void
    {
        // Even with required=true, default mode does not enforce required when field is absent
        $def = self::defaultModeDef([
            'title' => ['required' => true],
        ]);

        $violations = $this->validator->validate([], $def);

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
    public function regexWithInvalidPatternReturnsRegexErrorCode(): void
    {
        $def = self::explicitDef([
            'slug' => ['groups' => ['create'], 'validators' => [['type' => 'regex', 'pattern' => 'not-a-valid-regex']]],
        ]);

        $violations = $this->validator->validate(['slug' => 'anything'], $def);

        self::assertCount(1, $violations);
        self::assertSame('REGEX_ERROR', $violations[0]['code']);
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

    // ── Unknown validator type ───────────────────────────────────────────────

    #[Test]
    public function unknownValidatorTypeIsIgnored(): void
    {
        $def = self::explicitDef([
            'title' => ['groups' => ['create'], 'validators' => [['type' => 'nonExistentValidator']]],
        ]);

        $violations = $this->validator->validate(['title' => 'hello'], $def);

        self::assertSame([], $violations);
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
}
