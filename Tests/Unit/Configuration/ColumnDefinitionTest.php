<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Configuration;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ColumnDefinitionTest extends TestCase
{
    #[Test]
    public function validMinimalConfigCreatesDefinition(): void
    {
        $def = ColumnDefinition::fromArray([]);
        self::assertNull($def->groups);
        self::assertNull($def->type);
        self::assertFalse($def->required);
        self::assertNull($def->embed);
        self::assertNull($def->processor);
        self::assertNull($def->callback);
        self::assertSame([], $def->validators);
    }

    #[Test]
    public function validFullConfigCreatesDefinition(): void
    {
        $def = ColumnDefinition::fromArray([
            'groups' => ['list', 'show'],
            'type' => 'string',
            'required' => true,
            'embed' => true,
            'processor' => 'App\\MyProcessor',
            'resourceName' => 'colors',
            'resourceType' => 'Color',
            'validators' => [['type' => 'maxLength', 'max' => 100]],
            'column' => 'real_column',
            'callback' => ['App\\MyClass', 'myMethod'],
        ]);

        self::assertSame(['list', 'show'], $def->groups);
        self::assertSame('string', $def->type);
        self::assertTrue($def->required);
        self::assertTrue($def->embed);
        self::assertSame('App\\MyProcessor', $def->processor);
        self::assertSame('colors', $def->resourceName);
        self::assertSame('Color', $def->resourceType);
        self::assertCount(1, $def->validators);
        self::assertSame('real_column', $def->column);
        self::assertSame(['App\\MyClass', 'myMethod'], $def->callback);
    }

    #[Test]
    public function embedAsDepthArrayIsAccepted(): void
    {
        $def = ColumnDefinition::fromArray(['embed' => ['depth' => 3]]);
        self::assertSame(['depth' => 3], $def->embed);
        self::assertSame(3, $def->embedDepth());
    }

    #[Test]
    public function embedAsFalseIsAccepted(): void
    {
        $def = ColumnDefinition::fromArray(['embed' => false]);
        self::assertFalse($def->embed);
        self::assertSame(0, $def->embedDepth());
    }

    #[Test]
    public function groupsWithEmptyArrayTriggersExplicitMode(): void
    {
        $def = ColumnDefinition::fromArray(['groups' => []]);
        self::assertTrue($def->hasGroups());
        self::assertSame([], $def->groups);
    }

    // ── Invalid inputs ──────────────────────────────────────────────────

    #[Test]
    public function invalidGroupsValueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('groups');
        ColumnDefinition::fromArray(['groups' => 'list']);
    }

    #[Test]
    public function invalidGroupEntryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid operation');
        ColumnDefinition::fromArray(['groups' => ['list', 'bogus']]);
    }

    #[Test]
    #[DataProvider('invalidTypeProvider')]
    public function invalidTypeThrows(mixed $type): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('type');
        ColumnDefinition::fromArray(['type' => $type]);
    }

    public static function invalidTypeProvider(): \Generator
    {
        yield 'non-string' => [123];
        yield 'unknown type' => ['blob'];
    }

    #[Test]
    public function invalidRequiredThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('required');
        ColumnDefinition::fromArray(['required' => 'yes']);
    }

    #[Test]
    #[DataProvider('invalidEmbedProvider')]
    public function invalidEmbedThrows(mixed $embed): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('embed');
        ColumnDefinition::fromArray(['embed' => $embed]);
    }

    public static function invalidEmbedProvider(): \Generator
    {
        yield 'string' => ['deep'];
        yield 'integer' => [2];
        yield 'array without depth' => [['foo' => 'bar']];
    }

    #[Test]
    public function invalidProcessorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('processor');
        ColumnDefinition::fromArray(['processor' => 42]);
    }

    #[Test]
    #[DataProvider('invalidCallbackProvider')]
    public function invalidCallbackThrows(mixed $callback): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('callback');
        ColumnDefinition::fromArray(['callback' => $callback]);
    }

    public static function invalidCallbackProvider(): \Generator
    {
        yield 'string' => ['myFunction'];
        yield 'single-element array' => [['App\\MyClass']];
        yield 'three-element array' => [['App\\MyClass', 'method', 'extra']];
        yield 'non-string class' => [[123, 'method']];
        yield 'non-string method' => [['App\\MyClass', 42]];
    }

    #[Test]
    public function invalidValidatorsNotArrayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('validators');
        ColumnDefinition::fromArray(['validators' => 'maxLength']);
    }

    #[Test]
    public function invalidValidatorEntryNotArrayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('validators[0]');
        ColumnDefinition::fromArray(['validators' => ['maxLength']]);
    }

    #[Test]
    public function invalidValidatorTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('validators[0].type');
        ColumnDefinition::fromArray(['validators' => [['type' => 'unknown']]]);
    }

    #[Test]
    public function invalidRegexPatternThrowsAtDefinitionTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid regex pattern');
        ColumnDefinition::fromArray([
            'validators' => [['type' => 'regex', 'pattern' => 'not-a-valid-regex']],
        ]);
    }

    #[Test]
    public function invalidRegexPatternMissingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('validators[0].pattern');
        ColumnDefinition::fromArray([
            'validators' => [['type' => 'regex']],
        ]);
    }

    #[Test]
    public function validRegexPatternDoesNotThrow(): void
    {
        $def = ColumnDefinition::fromArray([
            'validators' => [['type' => 'regex', 'pattern' => '/^[a-z]+$/']],
        ]);
        self::assertCount(1, $def->validators);
    }

    #[Test]
    public function invalidColumnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('column');
        ColumnDefinition::fromArray(['column' => 42]);
    }

    #[Test]
    public function invalidResourceNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('resourceName');
        ColumnDefinition::fromArray(['resourceName' => 42]);
    }

    #[Test]
    public function invalidResourceTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('resourceType');
        ColumnDefinition::fromArray(['resourceType' => []]);
    }
}
