<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Registry;

class ApiRegistry
{
    private static array $resources = [];

    public static function register(string $resourceName, array $config): void
    {
        self::$resources[$resourceName] = $config;
    }

    public static function get(string $resourceName): ?array
    {
        return self::$resources[$resourceName] ?? null;
    }

    public static function reset(): void
    {
        self::$resources = [];
    }
}
