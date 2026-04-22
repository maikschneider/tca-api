<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Cache;

/**
 * Typed, normalised cache configuration for a single API resource.
 *
 * Created by CacheDefinition::fromArray() from the 'cache' key of a raw PHP config array.
 * When no 'cache' section is present, the ApiDefinition uses a default-disabled instance.
 */
final readonly class CacheDefinition
{
    private const DEFAULT_LIFETIME = 86400;

    /**
     * @param bool     $enabled            Whether caching is enabled for this resource
     * @param int      $lifetime           Cache TTL in seconds
     * @param string[] $parametersToIgnore  Query parameters that bypass caching entirely
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly int $lifetime = self::DEFAULT_LIFETIME,
        public readonly array $parametersToIgnore = [],
    ) {
    }

    /**
     * Creates a CacheDefinition from a raw PHP config array with validation.
     *
     * @throws \InvalidArgumentException when values are invalid
     */
    public static function fromArray(array $raw): self
    {
        $enabled = $raw['enabled'] ?? false;
        if (!\is_bool($enabled)) {
            throw new \InvalidArgumentException('cache.enabled must be a boolean.');
        }

        $lifetime = $raw['lifetime'] ?? self::DEFAULT_LIFETIME;
        if (!\is_int($lifetime) || $lifetime < 1) {
            throw new \InvalidArgumentException('cache.lifetime must be a positive integer.');
        }

        $parametersToIgnore = $raw['parametersToIgnore'] ?? [];
        if (!\is_array($parametersToIgnore)) {
            throw new \InvalidArgumentException('cache.parametersToIgnore must be an array.');
        }
        foreach ($parametersToIgnore as $param) {
            if (!\is_string($param) || $param === '') {
                throw new \InvalidArgumentException('cache.parametersToIgnore entries must be non-empty strings.');
            }
        }

        return new self(
            enabled: $enabled,
            lifetime: $lifetime,
            parametersToIgnore: $parametersToIgnore,
        );
    }
}
