<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Security;

use MaikSchneider\TcaApi\Security\Scope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopeTest extends TestCase
{
    public static function validityProvider(): \Generator
    {
        yield 'resource read' => ['articles:read', true];
        yield 'resource write' => ['sys-categories:write', true];
        yield 'all resources' => ['*:read', true];
        yield 'all operations' => ['articles:*', true];
        yield 'everything' => ['*:*', true];
        yield 'unknown access' => ['articles:delete', false];
        yield 'no access part' => ['articles', false];
        yield 'uppercase' => ['Articles:read', false];
        yield 'empty' => ['', false];
        yield 'not a string' => [42, false];
    }

    #[Test]
    #[DataProvider('validityProvider')]
    public function isValidRecognisesTheScopeFormat(mixed $scope, bool $expected): void
    {
        self::assertSame($expected, Scope::isValid($scope));
    }

    #[Test]
    public function forOperationMapsReadAndWriteOperations(): void
    {
        foreach (['list', 'show', 'userinfo'] as $operation) {
            self::assertSame('articles:read', Scope::forOperation('articles', $operation));
        }
        foreach (['create', 'update', 'delete'] as $operation) {
            self::assertSame('articles:write', Scope::forOperation('articles', $operation));
        }
    }

    public static function grantsProvider(): \Generator
    {
        yield 'exact' => [['articles:write'], 'articles:write', true];
        yield 'other access' => [['articles:read'], 'articles:write', false];
        yield 'other resource' => [['colors:write'], 'articles:write', false];
        yield 'resource wildcard' => [['*:write'], 'articles:write', true];
        yield 'access wildcard' => [['articles:*'], 'articles:write', true];
        yield 'any of several' => [['colors:read', 'articles:write'], 'articles:write', true];
        yield 'none granted' => [[], 'articles:read', false];
    }

    /**
     * @param list<string> $granted
     */
    #[Test]
    #[DataProvider('grantsProvider')]
    public function grantsMatchesWildcards(array $granted, string $required, bool $expected): void
    {
        self::assertSame($expected, Scope::grants($granted, $required));
    }
}
