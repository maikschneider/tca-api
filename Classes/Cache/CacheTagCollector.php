<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Cache;

/**
 * Stateful service that collects TYPO3 cache tags during API request processing.
 *
 * Activated by the RequestDispatcher when a cacheable operation is served.
 * The ResourceSerializer calls addTag() for every serialized entity.
 * After the response is built, collected tags are used for cache storage and response headers.
 */
final class CacheTagCollector
{
    private bool $active = false;

    /** @var array<string, bool> */
    private array $tags = [];

    private ?int $lifetimeLimit = null;

    /**
     * Activate tag collection for the current request.
     */
    public function activate(): void
    {
        $this->active = true;
        $this->tags = [];
        $this->lifetimeLimit = null;
    }

    /**
     * Add a cache tag (e.g. "tx_news_domain_model_news_123").
     * Only collected when the collector is active.
     */
    public function addTag(string $tag): void
    {
        if ($this->active) {
            $this->tags[$tag] = true;
        }
    }

    /**
     * @return string[] Unique collected cache tags
     */
    public function getTags(): array
    {
        return array_keys($this->tags);
    }

    /**
     * Limit the lifetime in seconds of the cache entry for the current request,
     * e.g. to the next starttime or endtime of a record. The smallest limit wins;
     * a limit below 1 skips storing the response.
     * Only collected when the collector is active.
     */
    public function limitLifetime(int $seconds): void
    {
        if ($this->active) {
            $this->lifetimeLimit = min($seconds, $this->lifetimeLimit ?? $seconds);
        }
    }

    /**
     * @return int|null Smallest collected lifetime limit, null when none was set
     */
    public function getLifetimeLimit(): ?int
    {
        return $this->lifetimeLimit;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Reset collector state for the next request.
     */
    public function reset(): void
    {
        $this->active = false;
        $this->tags = [];
        $this->lifetimeLimit = null;
    }
}
