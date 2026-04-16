<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Validation;

use MaikSchneider\TcaApi\Validation\FieldValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FieldValidator — specifically the defensive regex handling.
 */
final class FieldValidatorTest extends TestCase
{
    private FieldValidator $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new FieldValidator();
    }

    #[Test]
    public function invalidRegexPatternReturnsRegexErrorViolation(): void
    {
        // Config without 'groups' key → default mode (non-explicit)
        $config = [
            'columns' => [
                'email' => [
                    'validators' => [
                        ['type' => 'regex', 'pattern' => '[invalid-regex'],
                    ],
                ],
            ],
        ];

        $violations = $this->subject->validate(['email' => 'test@example.com'], $config);

        self::assertCount(1, $violations);
        self::assertSame('email', $violations[0]['propertyPath']);
        self::assertSame('REGEX_ERROR', $violations[0]['code']);
        self::assertStringContainsString('invalid validation pattern', $violations[0]['message']);
    }

    #[Test]
    public function validRegexPatternThatDoesNotMatchReturnsRegexViolation(): void
    {
        $config = [
            'columns' => [
                'code' => [
                    'validators' => [
                        ['type' => 'regex', 'pattern' => '/^[0-9]+$/'],
                    ],
                ],
            ],
        ];

        $violations = $this->subject->validate(['code' => 'not-a-number'], $config);

        self::assertCount(1, $violations);
        self::assertSame('code', $violations[0]['propertyPath']);
        self::assertSame('REGEX', $violations[0]['code']);
    }

    #[Test]
    public function validRegexPatternThatMatchesReturnsNoViolation(): void
    {
        $config = [
            'columns' => [
                'code' => [
                    'validators' => [
                        ['type' => 'regex', 'pattern' => '/^[0-9]+$/'],
                    ],
                ],
            ],
        ];

        $violations = $this->subject->validate(['code' => '12345'], $config);

        self::assertSame([], $violations);
    }
}
