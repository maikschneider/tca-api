<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * A pages resource scoped to the doktypes a frontend renders: standard (1),
 * link (3) and shortcut (4). Special doktypes stay unreachable.
 *
 * Pages below the root page 1:
 *   500 standard, 501 sysfolder (pages_record_types.csv)
 *   520 link to https://typo3.org, 521 shortcut to 500, 522 spacer, 523 recycler
 *
 * The external URL of a link page is the `link` column since TYPO3 v14 and `url`
 * before, so the link and shortcut pages are inserted here rather than in a CSV.
 */
final class PageDoktypesTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_record_types.csv');

        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $page = ['pid' => 1, 'perms_everybody' => 1, 'sorting' => 0];
        $connection->insert('pages', $page + ['uid' => 520, 'title' => 'TYPO3', 'slug' => '/typo3', 'doktype' => 3, self::linkColumn() => 'https://typo3.org']);
        $connection->insert('pages', $page + ['uid' => 521, 'title' => 'To standard', 'slug' => '/to-standard', 'doktype' => 4, 'shortcut' => 500, 'shortcut_mode' => 0]);
        $connection->insert('pages', $page + ['uid' => 522, 'title' => 'Spacer', 'slug' => '/spacer', 'doktype' => 199]);
        $connection->insert('pages', $page + ['uid' => 523, 'title' => 'Recycler', 'slug' => '/recycler', 'doktype' => 255]);

        $this->registerResource('web-pages', [
            'general' => [
                'table'        => 'pages',
                'resourceName' => 'web-pages',
                'resourceType' => 'WebPage',
                'recordTypes'  => [1, 3, 4],
            ],
            'security' => ['list' => AccessRole::PUBLIC, 'show' => AccessRole::PUBLIC],
            'order'    => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private static function linkColumn(): string
    {
        return isset($GLOBALS['TCA']['pages']['columns']['link']) ? 'link' : 'url';
    }

    public function testTheCollectionHoldsOnlyTheScopedDoktypes(): void
    {
        $members = $this->decodeResponseBody($this->executeApiRequest('/_api/web-pages'))['hydra:member'];

        self::assertSame([1, 500, 520, 521], array_column($members, 'uid'));
    }

    public function testSpecialDoktypesAreUnreachableByUid(): void
    {
        foreach ([501, 522, 523] as $uid) {
            self::assertSame(404, $this->executeApiRequest('/_api/web-pages/' . $uid)->getStatusCode(), 'uid ' . $uid);
        }
    }

    public function testALinkPageExposesItsExternalUrl(): void
    {
        $link     = $this->decodeResponseBody($this->executeApiRequest('/_api/web-pages/520'));
        $standard = $this->decodeResponseBody($this->executeApiRequest('/_api/web-pages/500'));

        self::assertStringContainsString('https://typo3.org', json_encode($link[self::linkColumn()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        self::assertArrayNotHasKey(self::linkColumn(), $standard);
    }

    public function testAShortcutPageExposesItsTarget(): void
    {
        $shortcut = $this->decodeResponseBody($this->executeApiRequest('/_api/web-pages/521'));

        self::assertSame('/_api/web-pages/500', $shortcut['shortcut']);
        self::assertSame(0, $shortcut['shortcut_mode']);
        self::assertArrayNotHasKey('shortcut', $this->decodeResponseBody($this->executeApiRequest('/_api/web-pages/500')));
    }

    public function testOpenApiDescribesEachDoktype(): void
    {
        $schemas = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'))['components']['schemas'];

        self::assertSame(['1', '3', '4'], array_map('strval', array_keys($schemas['WebPageRead']['discriminator']['mapping'])));
        self::assertArrayHasKey('shortcut', $schemas['WebPage4Read']['properties']);
        self::assertArrayNotHasKey('shortcut', $schemas['WebPage1Read']['properties']);
        self::assertArrayNotHasKey('items', $schemas['WebPage4Read']['properties']['shortcut']);
    }
}
