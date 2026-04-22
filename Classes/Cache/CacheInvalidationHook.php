<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Cache;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * DataHandler hook that flushes API cache entries when records change.
 *
 * Registered in ext_localconf.php as clearCachePostProc hook.
 * TYPO3 sends cache tags in the format ['tableName' => [uid1, uid2, ...]]
 * when records are created, updated, or deleted via the backend.
 */
final class CacheInvalidationHook
{
    public function __construct(
        private readonly FrontendInterface $cache,
    ) {
    }

    /**
     * @param array{tags?: array<string, mixed>} $params
     */
    public function clearCachePostProc(array $params): void
    {
        $tags = $params['tags'] ?? [];
        if ($tags === []) {
            return;
        }

        $cacheTags = [];
        foreach ($tags as $tag => $value) {
            if (\is_string($tag) && $tag !== '') {
                $cacheTags[] = $tag;
            }
        }

        if ($cacheTags !== []) {
            $this->cache->flushByTags($cacheTags);
        }
    }
}
