<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Registry;

class ApiRegistry
{
    private static array $resources = [];

    /** @var array<string, array> Reverse lookup: table name → config */
    private static array $tableIndex = [];

    public static function register(string $resourceName, array $config): void
    {
        self::$resources[$resourceName] = $config;

        $table = $config['general']['table'] ?? null;
        if ($table !== null) {
            self::$tableIndex[$table] = $config;
        }
    }

    public static function get(string $resourceName): ?array
    {
        return self::$resources[$resourceName] ?? null;
    }

    public static function getByTable(string $table): ?array
    {
        return self::$tableIndex[$table] ?? null;
    }

    public static function reset(): void
    {
        self::$resources = [];
        self::$tableIndex = [];
    }
}
