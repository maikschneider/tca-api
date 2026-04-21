<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Registry;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
final class ApiRegistry
{
    /**
     * Static backing store: TYPO3's testing framework creates a new DI container
     * per executeFrontendSubRequest() call (Bootstrap::init()), so instance
     * properties would not be shared between test and sub-request.  Static state
     * is the only reliable way to keep registrations visible across containers.
     *
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

    /**
     * Clear all registrations.  Called from test setUp() to prevent
     * cross-test leakage of the static backing store.
     */
    public function reset(): void
    {
        self::$resources = [];
    }
}
