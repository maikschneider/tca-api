<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Cache;

use MaikSchneider\TcaApi\Event\AfterWriteEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

#[AsEventListener]
final class WriteCacheInvalidationListener
{
    public function __construct(
        private readonly FrontendInterface $cache,
    ) {
    }

    public function __invoke(AfterWriteEvent $event): void
    {
        $table = $event->getTable();
        $uid = $event->getUid();

        $tags = match ($event->getOperation()) {
            'create' => [$table],
            'update', 'delete' => [$table, $table . '_' . $uid],
            default => [],
        };

        if ($tags !== []) {
            $this->cache->flushByTags($tags);
        }
    }
}
