<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Exception;

use MaikSchneider\TcaApi\Exception\UnknownResourceException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UnknownResourceExceptionTest extends TestCase
{
    // ── Factory method ───────────────────────────────────────────────────

    #[Test]
    public function forResourceCreatesInstanceWithCorrectMessage(): void
    {
        $exception = UnknownResourceException::forResource('articles');

        self::assertSame(
            'No TCA API resource registered for name "articles".',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function forResourceIncludesTheResourceNameInTheMessage(): void
    {
        $resourceName = 'my-custom-resource';
        $exception = UnknownResourceException::forResource($resourceName);

        self::assertStringContainsString($resourceName, $exception->getMessage());
    }

    #[Test]
    public function forResourceReturnsInstanceOfUnknownResourceException(): void
    {
        $exception = UnknownResourceException::forResource('products');

        self::assertInstanceOf(UnknownResourceException::class, $exception);
    }

    // ── Inheritance ──────────────────────────────────────────────────────

    #[Test]
    public function exceptionExtendsInvalidArgumentException(): void
    {
        $exception = UnknownResourceException::forResource('articles');

        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
    }

    #[Test]
    public function exceptionCanBeCaughtAsInvalidArgumentException(): void
    {
        $caught = null;

        try {
            throw UnknownResourceException::forResource('missing');
        } catch (\InvalidArgumentException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertInstanceOf(UnknownResourceException::class, $caught);
    }

    // ── Edge cases ───────────────────────────────────────────────────────

    #[Test]
    public function forResourceWithEmptyNameStillFormatsMessage(): void
    {
        $exception = UnknownResourceException::forResource('');

        self::assertSame(
            'No TCA API resource registered for name "".',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function forResourceWithSpecialCharactersInName(): void
    {
        $exception = UnknownResourceException::forResource('my-resource_v2/test');

        self::assertStringContainsString('my-resource_v2/test', $exception->getMessage());
    }

    #[Test]
    public function differentResourceNamesProduceDifferentMessages(): void
    {
        $exception1 = UnknownResourceException::forResource('articles');
        $exception2 = UnknownResourceException::forResource('products');

        self::assertNotSame($exception1->getMessage(), $exception2->getMessage());
    }
}