<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Cache;

use MaikSchneider\TcaApi\Cache\CacheTagCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CacheTagCollectorTest extends TestCase
{
    #[Test]
    public function isInactiveByDefault(): void
    {
        $collector = new CacheTagCollector();

        self::assertFalse($collector->isActive());
        self::assertSame([], $collector->getTags());
    }

    #[Test]
    public function activateMakesCollectorActive(): void
    {
        $collector = new CacheTagCollector();
        $collector->activate();

        self::assertTrue($collector->isActive());
    }

    #[Test]
    public function addTagWhenInactiveDoesNotCollect(): void
    {
        $collector = new CacheTagCollector();
        $collector->addTag('tx_news_domain_model_news_1');

        self::assertSame([], $collector->getTags());
    }

    #[Test]
    public function addTagWhenActiveCollectsTags(): void
    {
        $collector = new CacheTagCollector();
        $collector->activate();
        $collector->addTag('tx_news_domain_model_news_1');
        $collector->addTag('tx_news_domain_model_news_2');

        self::assertSame(['tx_news_domain_model_news_1', 'tx_news_domain_model_news_2'], $collector->getTags());
    }

    #[Test]
    public function duplicateTagsAreDeduped(): void
    {
        $collector = new CacheTagCollector();
        $collector->activate();
        $collector->addTag('tx_news_domain_model_news_1');
        $collector->addTag('tx_news_domain_model_news_1');
        $collector->addTag('tx_news_domain_model_news_2');

        self::assertSame(['tx_news_domain_model_news_1', 'tx_news_domain_model_news_2'], $collector->getTags());
    }

    #[Test]
    public function resetClearsTagsAndDeactivates(): void
    {
        $collector = new CacheTagCollector();
        $collector->activate();
        $collector->addTag('tx_news_domain_model_news_1');

        $collector->reset();

        self::assertFalse($collector->isActive());
        self::assertSame([], $collector->getTags());
    }

    #[Test]
    public function activateResetsPreviousTags(): void
    {
        $collector = new CacheTagCollector();
        $collector->activate();
        $collector->addTag('tx_news_domain_model_news_1');

        $collector->activate();

        self::assertTrue($collector->isActive());
        self::assertSame([], $collector->getTags());
    }
}
