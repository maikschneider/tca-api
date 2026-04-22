<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Cache;

use MaikSchneider\TcaApi\Cache\CacheInvalidationHook;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class CacheInvalidationHookTest extends TestCase
{
    #[Test]
    public function clearCachePostProcFlushesTagsFromParams(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())
            ->method('flushByTags')
            ->with(['tx_news_domain_model_news', 'pages']);

        $hook = new CacheInvalidationHook($cache);
        $hook->clearCachePostProc([
            'tags' => [
                'tx_news_domain_model_news' => [1, 2, 3],
                'pages' => [10],
            ],
        ]);
    }

    #[Test]
    public function clearCachePostProcWithEmptyTagsDoesNothing(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::never())->method('flushByTags');

        $hook = new CacheInvalidationHook($cache);
        $hook->clearCachePostProc(['tags' => []]);
    }

    #[Test]
    public function clearCachePostProcWithNoTagsKeyDoesNothing(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::never())->method('flushByTags');

        $hook = new CacheInvalidationHook($cache);
        $hook->clearCachePostProc([]);
    }

    #[Test]
    public function clearCachePostProcSkipsEmptyStringTag(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())
            ->method('flushByTags')
            ->with(['valid_tag']);

        $hook = new CacheInvalidationHook($cache);
        $hook->clearCachePostProc([
            'tags' => [
                'valid_tag' => [1],
                '' => [2],
            ],
        ]);
    }
}
