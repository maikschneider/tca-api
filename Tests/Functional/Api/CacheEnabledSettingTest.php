<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * site-a sets tenant.extrasEnabled to true, site-b to false; neither defines tenant.undefined.
 */
final class CacheEnabledSettingTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiSite' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_multisite.csv');
        $this->get(CacheManager::class)->getCache('tca_api')->flush();
    }

    /** @param array<string, mixed> $cache */
    private function registerCachedPages(array $cache): void
    {
        $this->registerResource('pages-cached', [
            'general' => [
                'table'        => 'pages',
                'resourceName' => 'pages-cached',
                'resourceType' => 'PageCached',
            ],
            'columns'  => ['title' => ['groups' => ['list', 'show']]],
            'security' => ['list' => AccessRole::PUBLIC, 'show' => AccessRole::PUBLIC],
            'cache'    => $cache,
        ]);
    }

    /** @return array{string, string} cache headers of two identical requests */
    private function requestTwice(string $url): array
    {
        $first = $this->executeFrontendSubRequest(new InternalRequest($url));
        $second = $this->executeFrontendSubRequest(new InternalRequest($url));
        self::assertSame(200, $first->getStatusCode());
        self::assertSame((string)$first->getBody(), (string)$second->getBody());

        return [$first->getHeaderLine('X-TCA-API-Cache'), $second->getHeaderLine('X-TCA-API-Cache')];
    }

    private function cacheEntryCount(): int
    {
        return (int)$this->get(ConnectionPool::class)
            ->getConnectionForTable('cache_tca_api')
            ->count('*', 'cache_tca_api', []);
    }

    /** @return iterable<string, array{string, array{string, string}, int}> */
    public static function settingValues(): iterable
    {
        yield 'true' => ['http://site-a.localhost/a-api/pages-cached', ['MISS', 'HIT'], 1];
        yield 'false' => ['http://site-b.localhost/b-api/pages-cached', ['', ''], 0];
    }

    #[Test]
    #[DataProvider('settingValues')]
    public function theCacheIsUsedOnlyWhereTheSettingIsTrue(string $url, array $headers, int $entries): void
    {
        $this->registerCachedPages(['enabled' => true, 'enabledSetting' => 'tenant.extrasEnabled']);

        self::assertSame($headers, $this->requestTwice($url));
        self::assertSame($entries, $this->cacheEntryCount());
    }

    #[Test]
    public function aMissingSettingDisablesTheCache(): void
    {
        $this->registerCachedPages(['enabled' => true, 'enabledSetting' => 'tenant.undefined']);

        self::assertSame(['', ''], $this->requestTwice('http://site-a.localhost/a-api/pages-cached'));
        self::assertSame(0, $this->cacheEntryCount());
    }

    #[Test]
    public function oneDefinitionIsCachedOnOneSiteAndUncachedOnTheOther(): void
    {
        $this->registerCachedPages(['enabled' => true, 'enabledSetting' => 'tenant.extrasEnabled']);

        self::assertSame(['MISS', 'HIT'], $this->requestTwice('http://site-a.localhost/a-api/pages-cached/1'));
        self::assertSame(['', ''], $this->requestTwice('http://site-b.localhost/b-api/pages-cached/1'));
        self::assertSame(1, $this->cacheEntryCount());
    }

    #[Test]
    public function theSettingDoesNotEnableADisabledCache(): void
    {
        $this->registerCachedPages(['enabled' => false, 'enabledSetting' => 'tenant.extrasEnabled']);

        self::assertSame(['', ''], $this->requestTwice('http://site-a.localhost/a-api/pages-cached'));
        self::assertSame(0, $this->cacheEntryCount());
    }

    #[Test]
    public function withoutTheKeyCacheEnabledDecidesOnEverySite(): void
    {
        $this->registerCachedPages(['enabled' => true]);

        self::assertSame(['MISS', 'HIT'], $this->requestTwice('http://site-a.localhost/a-api/pages-cached'));
        self::assertSame(['MISS', 'HIT'], $this->requestTwice('http://site-b.localhost/b-api/pages-cached'));
        self::assertSame(2, $this->cacheEntryCount());
    }
}
