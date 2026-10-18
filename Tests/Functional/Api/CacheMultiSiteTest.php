<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Two sites serve the same cached resource under different API prefixes, so a
 * response cached for one site and served to the other carries the wrong links.
 */
final class CacheMultiSiteTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiSite' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_multisite.csv');

        $this->registerResource('pages-cached', [
            'general' => [
                'table'        => 'pages',
                'resourceName' => 'pages-cached',
                'resourceType' => 'PageCached',
            ],
            'columns'  => ['title' => ['groups' => ['list', 'show']]],
            'security' => ['list' => AccessRole::PUBLIC, 'show' => AccessRole::PUBLIC],
            'cache'    => ['enabled' => true],
        ]);

        $this->get(CacheManager::class)->getCache('tca_api')->flush();
    }

    public function testEachSiteGetsItsOwnCacheEntry(): void
    {
        $siteA = $this->executeFrontendSubRequest(new InternalRequest('http://site-a.localhost/a-api/pages-cached'));
        $siteB = $this->executeFrontendSubRequest(new InternalRequest('http://site-b.localhost/b-api/pages-cached'));

        self::assertSame('MISS', $siteA->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('MISS', $siteB->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('/a-api/pages-cached', $this->decodeResponseBody($siteA)['@id']);
        self::assertSame('/b-api/pages-cached', $this->decodeResponseBody($siteB)['@id']);
    }
}
