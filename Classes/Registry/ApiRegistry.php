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
     * embed definitions apply again, and other relations follow pickRelated().
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
            return self::pickRelated([$config]);
        }

        $allowed = $blocked = [];
        foreach (self::$resources as $name => $resource) {
            if ($resource->table !== $foreignTable) {
                continue;
            }
            if ($isAllowed($name)) {
                $allowed[] = $resource;
            } else {
                $blocked[] = $resource->withoutItemEndpoint();
            }
        }

        return self::pickRelated([...$allowed, ...$blocked]);
    }

    /**
     * The resource an automatically resolved relation uses among the candidates for
     * the related table, those the site serves first.
     *
     * A resource scoped to record types answers 404 for a record of another type, and
     * a reference carries only the uid, not the type. So a resource serving every type
     * is preferred, and a scoped one is used without its item endpoint: its records
     * are referenced by uid instead of an IRI that might not dereference.
     *
     * @param list<ApiDefinition> $candidates
     */
    public static function pickRelated(array $candidates): ?ApiDefinition
    {
        foreach ($candidates as $candidate) {
            if (!$candidate->isRecordTypeScoped()) {
                return $candidate;
            }
        }

        return isset($candidates[0]) ? $candidates[0]->withoutItemEndpoint() : null;
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
