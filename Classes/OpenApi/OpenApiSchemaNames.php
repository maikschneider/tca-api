<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;

/**
 * Component schema names of the resources in one spec ("{name}Read", "{name}Write", …).
 *
 * The name is the resourceType. When several resources share a resourceType, e.g. a
 * public and an internal resource over the same table, the first keeps it and the
 * others append their resourceName ("Article" → "ArticleInternalArticles"), so no
 * resource's schemas overwrite another's.
 */
final readonly class OpenApiSchemaNames
{
    /** @var array<string, string> resourceName → schema name */
    private array $names;

    /** @param array<array-key, ApiDefinition> $resources */
    public function __construct(array $resources)
    {
        $names = [];
        // Every declared resourceType keeps its plain name, so a generated name can't take
        // the name of a resource registered later, which embedded $refs by resourceType rely on.
        $taken = [];
        foreach ($resources as $config) {
            $taken[$config->resourceType] = true;
        }
        $seenTypes = [];
        foreach ($resources as $config) {
            $name = $config->resourceType;
            if (isset($seenTypes[$config->resourceType])) {
                $name = $config->resourceType . self::pascalCase($config->resourceName);
                for ($counter = 2; isset($taken[$name]); $counter++) {
                    $name = $config->resourceType . self::pascalCase($config->resourceName) . $counter;
                }
            }
            $seenTypes[$config->resourceType] = true;
            $taken[$name] = true;
            $names[$config->resourceName] = $name;
        }
        $this->names = $names;
    }

    public function of(ApiDefinition $config): string
    {
        return $this->names[$config->resourceName] ?? $config->resourceType;
    }

    private static function pascalCase(string $value): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_map('ucfirst', $parts));
    }
}
