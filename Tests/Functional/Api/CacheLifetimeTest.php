<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApiTestCacheLifetime\LimitCacheLifetime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Listeners limit the lifetime of a cached response via CacheTagCollector::limitLifetime().
 * The database cache backend expires entries against EXEC_TIME, so the tests move it forward.
 */
final class CacheLifetimeTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../Fixtures/Extensions/test_cache_lifetime',
    ];

    private int $execTime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->get(CacheManager::class)->getCache('tca_api')->flush();
        LimitCacheLifetime::$limits = [];
        $this->execTime = $GLOBALS['EXEC_TIME'];

        $this->registerResource('cached-articles', [
            'general' => [
                'table' => 'tx_myext_domain_model_article',
                'resourceName' => 'cached-articles',
                'resourceType' => 'Article',
                'operations' => ['list', 'show'],
            ],
            'columns' => [
                'title' => ['type' => 'string', 'groups' => ['list', 'show']],
            ],
            'cache' => ['enabled' => true, 'lifetime' => 3600],
        ]);
    }

    protected function tearDown(): void
    {
        $GLOBALS['EXEC_TIME'] = $this->execTime;
        LimitCacheLifetime::$limits = [];
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pathProvider(): array
    {
        return [
            'list' => ['/_api/cached-articles'],
            'show' => ['/_api/cached-articles/1'],
        ];
    }

    #[Test]
    #[DataProvider('pathProvider')]
    public function entryExpiresAfterTheSmallestLimit(string $path): void
    {
        LimitCacheLifetime::$limits = [600, 60, 300];

        self::assertSame('MISS', $this->requestAt($path, 0));
        self::assertSame('HIT', $this->requestAt($path, 59));
        self::assertSame('MISS', $this->requestAt($path, 61));
    }

    #[Test]
    #[DataProvider('pathProvider')]
    public function entryWithoutLimitKeepsTheDefinitionLifetime(string $path): void
    {
        self::assertSame('MISS', $this->requestAt($path, 0));
        self::assertSame(1, $this->countCacheRows());
        self::assertSame('HIT', $this->requestAt($path, 3599));
        self::assertSame('MISS', $this->requestAt($path, 3601));
    }

    #[Test]
    public function limitAboveTheDefinitionLifetimeDoesNotExtendIt(): void
    {
        LimitCacheLifetime::$limits = [7200];

        self::assertSame('MISS', $this->requestAt('/_api/cached-articles', 0));
        self::assertSame('MISS', $this->requestAt('/_api/cached-articles', 3601));
    }

    #[Test]
    #[DataProvider('pathProvider')]
    public function limitBelowOneIsNotStored(string $path): void
    {
        LimitCacheLifetime::$limits = [0];

        self::assertSame('MISS', $this->requestAt($path, 0));
        self::assertSame(0, $this->countCacheRows());
        self::assertSame('MISS', $this->requestAt($path, 0));
    }

    #[Test]
    #[DataProvider('pathProvider')]
    public function eachVariedEntryExpiresAfterTheLimit(string $path): void
    {
        $this->registerResource('cached-articles', [
            'general' => [
                'table' => 'tx_myext_domain_model_article',
                'resourceName' => 'cached-articles',
                'resourceType' => 'Article',
                'operations' => ['list', 'show'],
            ],
            'columns' => [
                'title' => ['type' => 'string', 'groups' => ['list', 'show']],
            ],
            'cache' => ['enabled' => true, 'lifetime' => 3600, 'varyByParameters' => ['path']],
        ]);
        LimitCacheLifetime::$limits = [60];

        self::assertSame('MISS', $this->requestAt($path, 0, ['path' => '/about']));
        self::assertSame('MISS', $this->requestAt($path, 0, ['path' => '/access']));
        self::assertSame(2, $this->countCacheRows());
        self::assertSame('HIT', $this->requestAt($path, 59, ['path' => '/about']));
        self::assertSame('HIT', $this->requestAt($path, 59, ['path' => '/access']));
        self::assertSame('MISS', $this->requestAt($path, 61, ['path' => '/about']));
        self::assertSame('MISS', $this->requestAt($path, 61, ['path' => '/access']));
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function requestAt(string $path, int $secondsLater, array $queryParams = []): string
    {
        $GLOBALS['EXEC_TIME'] = $this->execTime + $secondsLater;

        return $this->executeApiRequest($path, $queryParams)->getHeaderLine('X-TCA-API-Cache');
    }

    private function countCacheRows(): int
    {
        return (int)$this->get(ConnectionPool::class)
            ->getConnectionForTable('cache_tca_api')
            ->count('*', 'cache_tca_api', []);
    }
}
