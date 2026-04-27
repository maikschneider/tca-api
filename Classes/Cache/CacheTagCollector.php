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

    /**
     * Activate tag collection for the current request.
     */
    public function activate(): void
    {
        $this->active = true;
        $this->tags = [];
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
    }
}
