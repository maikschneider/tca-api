<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Dispatcher\RequestDispatcher;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * storagePid / readStoragePids as a {$site.setting} placeholder, resolved per request.
 *
 * Two sites (Sites_MultiSite) set tenant.storagePid to their own folder:
 *   site-a (http://site-a.localhost, prefix /a-api/) → pid 801
 *   site-b (http://site-b.localhost, prefix /b-api/) → pid 802
 * Both set tenant.emptyPid to ''.
 *
 * Fixture records (articles_multisite.csv):
 *   uid=7700 → pid 801 (site A), uid=7701 → pid 802 (site B)
 */
final class SiteStoragePidTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';
    private const COLOR_TABLE   = 'tx_myext_domain_model_color';

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiSite' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_multisite.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles_multisite.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');

        $this->registerResource('tenant-colors', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'tenant-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show', 'create'],
                'storagePid'   => '{$tenant.storagePid}',
            ],
            'columns'  => ['name' => ['groups' => ['list', 'show', 'create']]],
            'security' => ['create' => AccessRole::FE_USER],
        ]);
        $this->registerResource('tenant-articles', self::articleConfig('tenant-articles', '{$tenant.storagePid}'));
        $this->registerResource('broken-articles', self::articleConfig('broken-articles', '{$tenant.emptyPid}'));

        $brokenReadConfig = self::articleConfig('broken-read-articles', '{$tenant.storagePid}');
        $brokenReadConfig['general']['readStoragePids'] = ['{$tenant.emptyPid}'];
        $this->registerResource('broken-read-articles', $brokenReadConfig);

        $cachedConfig = self::articleConfig('cached-tenant-articles', '{$tenant.storagePid}');
        $cachedConfig['cache'] = ['enabled' => true];
        $this->registerResource('cached-tenant-articles', $cachedConfig);
        $this->get(CacheManager::class)->getCache('tca_api')->flush();
    }

    private static function articleConfig(string $resourceName, string $storagePid): array
    {
        return [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => $resourceName,
                'resourceType' => 'Article',
                'operations'   => ['list', 'show', 'create', 'update', 'delete'],
                'storagePid'   => $storagePid,
            ],
            'columns' => [
                'title'                => ['groups' => ['list', 'show', 'create', 'update']],
                'related_items_inline' => ['groups' => ['show', 'create', 'update'], 'resourceName' => 'tenant-colors'],
                'profile_photo'        => [
                    'groups' => ['show', 'create', 'update'],
                    'upload' => ['folder' => '1:/user_upload/', 'maxSize' => '5M'],
                ],
            ],
            'security' => [
                'list'   => AccessRole::PUBLIC,
                'show'   => AccessRole::PUBLIC,
                'create' => AccessRole::FE_USER,
                'update' => AccessRole::FE_USER,
                'delete' => AccessRole::FE_USER,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ];
    }

    private function onSite(string $site): string
    {
        $this->apiHost = 'http://site-' . $site . '.localhost';

        return '/' . $site . '-api';
    }

    public function testEachSiteListsOnlyItsOwnRecords(): void
    {
        $siteA = $this->decodeResponseBody($this->executeApiRequest($this->onSite('a') . '/tenant-articles'));
        $siteB = $this->decodeResponseBody($this->executeApiRequest($this->onSite('b') . '/tenant-articles'));

        self::assertSame([7700], array_column($siteA['hydra:member'], 'uid'));
        self::assertSame(1, $siteA['hydra:totalItems']);
        self::assertSame([7701], array_column($siteB['hydra:member'], 'uid'));
    }

    public function testShowUpdateAndDeleteOfAnotherSitesRecordAnswer404(): void
    {
        $prefix = $this->onSite('a');

        self::assertSame(200, $this->executeApiRequest($prefix . '/tenant-articles/7700')->getStatusCode());
        self::assertSame(404, $this->executeApiRequest($prefix . '/tenant-articles/7701')->getStatusCode());
        self::assertSame(404, $this->executeApiWriteRequestAs('PATCH', $prefix . '/tenant-articles/7701', 1, ['title' => 'Hijacked'])->getStatusCode());
        self::assertSame(404, $this->executeApiWriteRequestAs('DELETE', $prefix . '/tenant-articles/7701', 1)->getStatusCode());
        self::assertSame('Site B Article', $this->fetchRow(self::ARTICLE_TABLE, 7701)['title']);
    }

    public function testCreateStoresTheRecordAndItsInlineChildrenOnTheSitesPid(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', $this->onSite('b') . '/tenant-articles', 1, [
            'title'                => 'New on B',
            'related_items_inline' => [['name' => 'Child on B']],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $uid = (int)$this->decodeResponseBody($response)['uid'];
        self::assertSame(802, (int)$this->fetchRow(self::ARTICLE_TABLE, $uid)['pid']);

        $child = $this->getConnectionPool()->getConnectionForTable(self::COLOR_TABLE)
            ->select(['pid'], self::COLOR_TABLE, ['name' => 'Child on B'])->fetchAssociative();
        self::assertSame(802, (int)($child['pid'] ?? 0));
    }

    public function testFileReferencesAreStoredOnTheSitesPid(): void
    {
        $jpeg = $this->createUploadedFile("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 96), 'photo.jpg', 'image/jpeg');

        $response = $this->executeApiMultipartWriteRequestAs('POST', $this->onSite('a') . '/tenant-articles', 1, ['title' => 'With photo'], ['profile_photo' => $jpeg]);

        self::assertSame(201, $response->getStatusCode());
        $reference = $this->getConnectionPool()->getConnectionForTable('sys_file_reference')
            ->select(['pid'], 'sys_file_reference', [
                'uid_foreign' => (int)$this->decodeResponseBody($response)['uid'],
                'tablenames'  => self::ARTICLE_TABLE,
                'fieldname'   => 'profile_photo',
            ])->fetchAssociative();
        self::assertSame(801, (int)($reference['pid'] ?? 0));
    }

    public function testAnEmptySettingFailsClosed(): void
    {
        $prefix = $this->onSite('a');

        self::assertSame(500, $this->executeApiRequest($prefix . '/broken-articles')->getStatusCode());
        self::assertSame(500, $this->executeApiRequest($prefix . '/broken-articles/7700')->getStatusCode());

        $create = $this->executeApiWriteRequestAs('POST', $prefix . '/broken-articles', 1, ['title' => 'Nowhere']);
        self::assertSame(500, $create->getStatusCode());
        self::assertFalse($this->getConnectionPool()->getConnectionForTable(self::ARTICLE_TABLE)
            ->select(['uid'], self::ARTICLE_TABLE, ['title' => 'Nowhere'])->fetchAssociative());
    }

    public function testAnInvalidReadSettingPreventsCreateFromWritingRecords(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', $this->onSite('a') . '/broken-read-articles', 1, [
            'title' => 'Must not persist',
            'related_items_inline' => [['name' => 'Must not persist either']],
        ]);

        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($this->getConnectionPool()->getConnectionForTable(self::ARTICLE_TABLE)
            ->select(['uid'], self::ARTICLE_TABLE, ['title' => 'Must not persist'])->fetchAssociative());
        self::assertFalse($this->getConnectionPool()->getConnectionForTable(self::COLOR_TABLE)
            ->select(['uid'], self::COLOR_TABLE, ['name' => 'Must not persist either'])->fetchAssociative());
    }

    public function testCachedReadsFollowChangedSiteStoragePages(): void
    {
        $listA = $this->dispatchCachedRequest(801);
        self::assertSame(200, $listA->getStatusCode(), (string)$listA->getBody());
        self::assertSame('MISS', $listA->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('HIT', $this->dispatchCachedRequest(801)->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('MISS', $this->dispatchCachedRequest(801, '/7700')->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('HIT', $this->dispatchCachedRequest(801, '/7700')->getHeaderLine('X-TCA-API-Cache'));

        $listB = $this->dispatchCachedRequest(802);
        self::assertSame('MISS', $listB->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame([7701], array_column($this->decodeResponseBody($listB)['hydra:member'], 'uid'));
        self::assertSame(404, $this->dispatchCachedRequest(802, '/7700')->getStatusCode());
    }

    public function testInvalidSiteStoragePagesCannotServeCachedResponses(): void
    {
        self::assertSame(200, $this->dispatchCachedRequest(801)->getStatusCode());
        self::assertSame(200, $this->dispatchCachedRequest(801, '/7700')->getStatusCode());

        self::assertSame(500, $this->dispatchCachedRequest('')->getStatusCode());
        self::assertSame(500, $this->dispatchCachedRequest('', '/7700')->getStatusCode());
    }

    private function dispatchCachedRequest(int|string $pid, string $suffix = ''): ResponseInterface
    {
        // Keep the site identifier and URL fixed while changing its settings,
        // without flushing the response cache between requests.
        $site = new Site('site-a', 1, ['base' => 'http://site-a.localhost/', 'settings' => [
            'tca_api' => ['apiPrefix' => '/a-api/', 'debugMode' => true],
            'tenant' => ['storagePid' => $pid],
        ]]);
        $request = (new ServerRequest('http://site-a.localhost/a-api/cached-tenant-articles' . $suffix))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('site', $site)
            ->withAttribute('tca_api.api_prefix', '/a-api');
        $previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $GLOBALS['TYPO3_REQUEST'] = $request;
        try {
            return $this->get(RequestDispatcher::class)->dispatch($request, $site->getSettings());
        } finally {
            $GLOBALS['TYPO3_REQUEST'] = $previousRequest;
        }
    }

    private function fetchRow(string $table, int $uid): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable($table)
            ->select(['*'], $table, ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }
}
