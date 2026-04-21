<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Registry;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
final class ApiRegistry
{
    /** @var array<string, ApiDefinition> */
    private array $resources = [];

    public function register(string $resourceName, ApiDefinition $definition): void
    {
        $this->resources[$resourceName] = $definition;
    }

    public function get(string $resourceName): ?ApiDefinition
    {
        return $this->resources[$resourceName] ?? null;
    }

    public function getByTable(string $table): ?ApiDefinition
    {
        foreach ($this->resources as $definition) {
            if ($definition->table === $table) {
                return $definition;
            }
        }

        return null;
    }

    /** @return array<string, ApiDefinition> */
    public function getAll(): array
    {
        return $this->resources;
    }

    /** @param array<string, ApiDefinition> $resources */
    public function replaceAll(array $resources): void
    {
        $this->resources = $resources;
    }
}
