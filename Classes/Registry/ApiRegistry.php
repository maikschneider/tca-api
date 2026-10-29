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
     *
     * @param list<string> $allowedResourceNames the site's tca_api.allowedResources; empty = all.
     *                                           A resource the site doesn't serve keeps its columns,
     *                                           but loses its item endpoint, so it isn't linked.
     */
    public function getRelated(string $foreignTable, ApiDefinition $config, ?string $resourceName = null, array $allowedResourceNames = []): ?ApiDefinition
    {
        $isAllowed = static fn (string $name): bool => $allowedResourceNames === [] || \in_array($name, $allowedResourceNames, true);

        if ($resourceName !== null) {
            $resource = $this->get($resourceName);
            return $resource !== null && !$isAllowed($resourceName) ? $resource->withoutItemEndpoint() : $resource;
        }

        if ($foreignTable === $config->table) {
            return $config;
        }

        if ($allowedResourceNames === []) {
            return $this->getByTable($foreignTable);
        }
        $blocked = null;
        foreach (self::$resources as $name => $resource) {
            if ($resource->table !== $foreignTable) {
                continue;
            }
            if ($isAllowed($name)) {
                return $resource;
            }
            $blocked ??= $resource;
        }

        return $blocked?->withoutItemEndpoint();
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
