<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Registry;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;

final class ApiRegistry
{
    /** @var array<string, ApiDefinition> */
    private static array $resources = [];

    public static function register(string $resourceName, ApiDefinition $definition): void
    {
        self::$resources[$resourceName] = $definition;
    }

    public static function get(string $resourceName): ?ApiDefinition
    {
        return self::$resources[$resourceName] ?? null;
    }

    public static function getByTable(string $table): ?ApiDefinition
    {
        foreach (self::$resources as $definition) {
            if ($definition->table === $table) {
                return $definition;
            }
        }

        return null;
    }

    /** @return array<string, ApiDefinition> */
    public static function getAll(): array
    {
        return self::$resources;
    }

    public static function reset(): void
    {
        self::$resources = [];
    }
}
