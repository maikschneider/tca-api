<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Cache;

use MaikSchneider\TcaApi\Cache\CacheDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CacheDefinitionTest extends TestCase
{
    // ── Happy-path tests ────────────────────────────────────────────────

    #[Test]
    public function defaultsAreDisabledWithDefaultLifetime(): void
    {
        $def = new CacheDefinition();

        self::assertFalse($def->enabled);
        self::assertSame(86400, $def->lifetime);
        self::assertSame([], $def->parametersToIgnore);
    }

    #[Test]
    public function fromArrayWithAllOptions(): void
    {
        $def = CacheDefinition::fromArray([
            'enabled' => true,
            'lifetime' => 3600,
            'parametersToIgnore' => ['search', 'debug'],
        ]);

        self::assertTrue($def->enabled);
        self::assertSame(3600, $def->lifetime);
        self::assertSame(['search', 'debug'], $def->parametersToIgnore);
    }

    #[Test]
    public function fromArrayWithMinimalConfig(): void
    {
        $def = CacheDefinition::fromArray(['enabled' => true]);

        self::assertTrue($def->enabled);
        self::assertSame(86400, $def->lifetime);
        self::assertSame([], $def->parametersToIgnore);
    }

    #[Test]
    public function fromArrayWithEmptyArrayCreatesDisabledInstance(): void
    {
        $def = CacheDefinition::fromArray([]);

        self::assertFalse($def->enabled);
    }

    #[Test]
    public function varyByParametersIsLoaded(): void
    {
        self::assertSame([], CacheDefinition::fromArray([])->varyByParameters);
        self::assertSame(['path', 'depth'], CacheDefinition::fromArray(['varyByParameters' => ['path', 'depth']])->varyByParameters);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidVaryByParameters(): iterable
    {
        yield 'string' => ['path', 'must be an array'];
        yield 'boolean' => [false, 'must be an array'];
        yield 'empty name' => [[''], 'entries must be non-empty strings'];
        yield 'integer name' => [[42], 'entries must be non-empty strings'];
        yield 'nested array' => [[['path']], 'entries must be non-empty strings'];
    }

    #[Test]
    #[DataProvider('invalidVaryByParameters')]
    public function invalidVaryByParametersThrows(mixed $value, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cache.varyByParameters ' . $message);
        CacheDefinition::fromArray(['varyByParameters' => $value]);
    }

    // ── Validation tests ────────────────────────────────────────────────

    #[Test]
    public function nonBooleanEnabledThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cache.enabled must be a boolean');
        CacheDefinition::fromArray(['enabled' => 'yes']);
    }

    #[Test]
    public function zeroLifetimeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cache.lifetime must be a positive integer');
        CacheDefinition::fromArray(['enabled' => true, 'lifetime' => 0]);
    }

    #[Test]
    public function negativeLifetimeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cache.lifetime must be a positive integer');
        CacheDefinition::fromArray(['enabled' => true, 'lifetime' => -100]);
    }

    #[Test]
    public function nonIntLifetimeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cache.lifetime must be a positive integer');
        CacheDefinition::fromArray(['enabled' => true, 'lifetime' => '3600']);
    }

    #[Test]
    public function parametersToIgnoreNotArrayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cache.parametersToIgnore must be an array');
        CacheDefinition::fromArray(['enabled' => true, 'parametersToIgnore' => 'search']);
    }

    #[Test]
    public function parametersToIgnoreWithEmptyStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cache.parametersToIgnore entries must be non-empty strings');
        CacheDefinition::fromArray(['enabled' => true, 'parametersToIgnore' => ['']]);
    }

    #[Test]
    public function parametersToIgnoreWithNonStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cache.parametersToIgnore entries must be non-empty strings');
        CacheDefinition::fromArray(['enabled' => true, 'parametersToIgnore' => [42]]);
    }
}
