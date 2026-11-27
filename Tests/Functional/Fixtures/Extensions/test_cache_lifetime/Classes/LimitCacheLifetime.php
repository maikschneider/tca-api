<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApiTestCacheLifetime;

use MaikSchneider\TcaApi\Cache\CacheTagCollector;
use MaikSchneider\TcaApi\Event\AfterOperationEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Passes every limit in self::$limits to CacheTagCollector::limitLifetime().
 * Tests set the limits; an empty list leaves the lifetime untouched.
 */
#[AsEventListener('test-cache-lifetime/limit')]
final class LimitCacheLifetime
{
    /** @var list<int> */
    public static array $limits = [];

    public function __construct(private readonly CacheTagCollector $cacheTagCollector)
    {
    }

    public function __invoke(AfterOperationEvent $event): void
    {
        foreach (self::$limits as $seconds) {
            $this->cacheTagCollector->limitLifetime($seconds);
        }
    }
}
