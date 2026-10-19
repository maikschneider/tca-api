<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Registry;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
final class ApiRegistry
{
    /**
     * @var array<string, ApiDefinition>
     */
    private static array $resources = [];

    public function register(string $resourceName, ApiDefinition $definition): void
    {
        self::$resources[$resourceName] = $definition;
    }

    public function get(string $resourceName): ?ApiDefinition
    {
        return self::$resources[$resourceName] ?? null;
    }

    public function getByTable(string $table): ?ApiDefinition
    {
        foreach (self::$resources as $definition) {
            if ($definition->table === $table) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * The config a relation from $config to $foreignTable serializes with.
     * An explicit $resourceName selects that registration by name, also on the
     * same table; otherwise a self-referential relation keeps $config so its
     * embed definitions apply again.
     */
    public function getRelated(string $foreignTable, ApiDefinition $config, ?string $resourceName = null): ?ApiDefinition
    {
        if ($resourceName !== null) {
            return $this->get($resourceName);
        }

        if ($foreignTable === $config->table) {
            return $config;
        }

        return $this->getByTable($foreignTable);
    }

    /** @return array<string, ApiDefinition> */
    public function getAll(): array
    {
        return self::$resources;
    }

    /** @param array<string, ApiDefinition> $resources */
    public function replaceAll(array $resources): void
    {
        self::$resources = $resources;
    }

    public function reset(): void
    {
        self::$resources = [];
    }
}
