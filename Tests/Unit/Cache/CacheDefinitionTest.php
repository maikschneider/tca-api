<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Cache;

use MaikSchneider\TcaApi\Cache\CacheDefinition;
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
