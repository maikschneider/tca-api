<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Registry;

final class ApiRegistry
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

    public static function getByTable(string $table): ?array
    {
        foreach (self::$resources as $config) {
            if (($config['general']['table'] ?? '') === $table) {
                return $config;
            }
        }
        return null;
    }

    public static function getAll(): array
    {
        return self::$resources;
    }

    public static function reset(): void
    {
        self::$resources = [];
    }
}
