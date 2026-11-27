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
    public function lifetimeLimitIsNullByDefault(): void
    {
        $collector = new CacheTagCollector();
        $collector->activate();

        self::assertNull($collector->getLifetimeLimit());
    }

    #[Test]
    public function smallestLifetimeLimitWins(): void
    {
        $collector = new CacheTagCollector();
        $collector->activate();
        $collector->limitLifetime(600);
        $collector->limitLifetime(60);
        $collector->limitLifetime(300);

        self::assertSame(60, $collector->getLifetimeLimit());
    }

    #[Test]
    public function limitLifetimeWhenInactiveIsIgnored(): void
    {
        $collector = new CacheTagCollector();
        $collector->limitLifetime(60);

        self::assertNull($collector->getLifetimeLimit());
    }

    #[Test]
    public function resetClearsLifetimeLimit(): void
    {
        $collector = new CacheTagCollector();
        $collector->activate();
        $collector->limitLifetime(60);

        $collector->reset();

        self::assertNull($collector->getLifetimeLimit());
    }

    #[Test]
    public function activateClearsPreviousLifetimeLimit(): void
    {
        $collector = new CacheTagCollector();
        $collector->activate();
        $collector->limitLifetime(60);

        $collector->activate();

        self::assertNull($collector->getLifetimeLimit());
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
