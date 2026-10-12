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

    #[Test]
    public function clearCachePostProcFlushesTagsFromAListOfTags(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())
            ->method('flushByTags')
            ->with(['pageId_1', 'tx_foo_42']);

        $hook = new CacheInvalidationHook($cache);
        $hook->clearCachePostProc(['cacheCmd' => '1', 'tags' => ['pageId_1', 'tx_foo_42']]);
    }

    #[Test]
    public function clearCachePostProcSkipsListEntriesThatAreNotTags(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())
            ->method('flushByTags')
            ->with(['valid_tag']);

        $hook = new CacheInvalidationHook($cache);
        $hook->clearCachePostProc(['tags' => ['', 42, null, 'valid_tag']]);
    }

    #[Test]
    public function clearCachePostProcFlushesNumericMapKeysAsStrings(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())
            ->method('flushByTags')
            ->with(['tx_foo', '42']);

        $hook = new CacheInvalidationHook($cache);
        $hook->clearCachePostProc(['tags' => ['tx_foo' => true, '42' => true]]);
    }

    #[Test]
    public function clearCachePostProcFlushesKeysOfAZeroBasedTagMap(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())
            ->method('flushByTags')
            ->with(['0']);

        $hook = new CacheInvalidationHook($cache);
        $hook->clearCachePostProc(['tags' => [0 => true]]);
    }
}
