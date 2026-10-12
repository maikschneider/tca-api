<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Cache;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * DataHandler hook that flushes API cache entries when records change.
 *
 * Registered in ext_localconf.php as clearCachePostProc hook. DataHandler calls it
 * with two shapes of $params['tags']: a map of tag => true after a record change
 * (e.g. ['tx_foo' => true, 'tx_foo_42' => true]), and a list of tags from
 * clear_cacheCmd() — TCEMAIN.clearCacheCmd, cacheTag:… commands, the cache menu.
 */
final class CacheInvalidationHook
{
    public function __construct(
        private readonly FrontendInterface $cache,
    ) {
    }

    /**
     * @param array{tags?: array<array-key, mixed>} $params
     */
    public function clearCachePostProc(array $params): void
    {
        $tags = $params['tags'] ?? [];
        if ($tags === []) {
            return;
        }

        $candidates = array_is_list($tags)
            ? array_filter($tags, \is_string(...))
            : array_map(\strval(...), array_keys($tags));

        $cacheTags = array_values(array_filter($candidates, static fn (string $tag): bool => $tag !== ''));

        if ($cacheTags !== []) {
            $this->cache->flushByTags($cacheTags);
        }
    }
}
