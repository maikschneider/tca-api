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
     * @param string[] $parametersToIgnore Query parameters that bypass caching entirely
     * @param string[] $varyByParameters   Additional top-level query parameters included in the cache key
     * @param string|null $enabledSetting  Boolean site setting that must be true for the cache to be used;
     *                                      null = $enabled decides on every site
     */
    public function __construct(
        public bool $enabled = false,
        public int $lifetime = self::DEFAULT_LIFETIME,
        public array $parametersToIgnore = [],
        public array $varyByParameters = [],
        public ?string $enabledSetting = null,
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

        $varyByParameters = \array_key_exists('varyByParameters', $raw) ? $raw['varyByParameters'] : [];
        if (!\is_array($varyByParameters)) {
            throw new \InvalidArgumentException('cache.varyByParameters must be an array.');
        }
        foreach ($varyByParameters as $param) {
            if (!\is_string($param) || $param === '') {
                throw new \InvalidArgumentException('cache.varyByParameters entries must be non-empty strings.');
            }
        }

        $enabledSetting = $raw['enabledSetting'] ?? null;
        if ($enabledSetting !== null && (!\is_string($enabledSetting) || preg_match('/^[A-Za-z0-9_.-]+$/', $enabledSetting) !== 1)) {
            throw new \InvalidArgumentException('cache.enabledSetting must be the name of a site setting, e.g. "myext.cache.enabled".');
        }

        return new self(
            enabled: $enabled,
            lifetime: $lifetime,
            parametersToIgnore: $parametersToIgnore,
            varyByParameters: $varyByParameters,
            enabledSetting: $enabledSetting,
        );
    }
}
