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
 *
 * tx_testrecordtypes_item.target (test_record_types fixture) is a manyToOne group
 * over pages and tt_content; target_page is a manyToOne group over pages only.
 */
final class PageDoktypesTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../Fixtures/Extensions/test_record_types',
    ];

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
            // A relation links through a resource scoped to record types only when it names it.
            'columns'  => ['shortcut' => ['resourceName' => 'web-pages']],
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
        self::assertSame([
            ['type' => 'string', 'format' => 'iri-reference', 'pattern' => '/web-pages/[0-9]+$', 'x-resource' => 'web-pages'],
            ['type' => 'null'],
        ], $schemas['WebPage4Read']['properties']['shortcut']['oneOf']);
        self::assertArrayNotHasKey('items', $schemas['WebPage4Read']['properties']['shortcut']);

        $hydra = $this->decodeResponseBody($this->executeApiRequest('/_api/docs.jsonld'));
        $class = array_values(array_filter($hydra['hydra:supportedClass'], static fn (array $class): bool => $class['hydra:title'] === 'WebPage'))[0];
        $shortcut = array_values(array_filter($class['hydra:supportedProperty'], static fn (array $property): bool => $property['hydra:title'] === 'shortcut'))[0];
        self::assertSame('#WebPage', $shortcut['hydra:property']['range']);
        self::assertSame(1, $shortcut['hydra:property']['owl:maxCardinality']);
    }

    public function testAManyToOneGroupOverOneTableIsSingular(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_testrecordtypes_item')
            ->insert('tx_testrecordtypes_item', ['uid' => 710, 'pid' => 1, 'item_type' => 'article', 'title' => 'Item', 'target_page' => '500']);
        $this->registerResource('items', [
            'general'  => ['table' => 'tx_testrecordtypes_item', 'resourceName' => 'items', 'resourceType' => 'Item'],
            'columns'  => ['target_page' => ['groups' => ['show'], 'resourceName' => 'web-pages']],
            'security' => ['list' => AccessRole::PUBLIC, 'show' => AccessRole::PUBLIC],
        ]);

        $response = $this->executeApiRequest('/_api/items/710');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/_api/web-pages/500', $this->decodeResponseBody($response)['target_page']);

        $schema = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'))['components']['schemas']['ItemRead']['properties']['target_page'];
        self::assertSame([
            ['type' => 'string', 'format' => 'iri-reference', 'pattern' => '/web-pages/[0-9]+$', 'x-resource' => 'web-pages'],
            ['type' => 'null'],
        ], $schema['oneOf']);
        self::assertArrayNotHasKey('items', $schema);
    }

    public function testHydraDescribesAGroupOverOneTableWithoutALinkAsAnInteger(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_testrecordtypes_item')
            ->insert('tx_testrecordtypes_item', ['uid' => 710, 'pid' => 1, 'item_type' => 'article', 'title' => 'Item', 'target_page' => '500']);
        $this->registerResource('items', [
            'general'  => ['table' => 'tx_testrecordtypes_item', 'resourceName' => 'items', 'resourceType' => 'Item'],
            'columns'  => ['target_page' => ['groups' => ['show']]],
            'security' => ['list' => AccessRole::PUBLIC, 'show' => AccessRole::PUBLIC],
        ]);
        $this->registerResource('web-pages', [
            'general'  => ['table' => 'pages', 'resourceName' => 'web-pages', 'resourceType' => 'WebPage', 'recordTypes' => [1, 3, 4]],
            'security' => ['list' => AccessRole::PUBLIC, 'show' => AccessRole::PUBLIC],
        ]);

        // web-pages serves only some doktypes, so neither relation links through it.
        self::assertSame(500, $this->decodeResponseBody($this->executeApiRequest('/_api/items/710'))['target_page']);
        self::assertSame(500, $this->decodeResponseBody($this->executeApiRequest('/_api/web-pages/521'))['shortcut']);

        $hydra = $this->decodeResponseBody($this->executeApiRequest('/_api/docs.jsonld'));
        foreach (['Item' => 'target_page', 'WebPage' => 'shortcut'] as $type => $column) {
            $class    = array_values(array_filter($hydra['hydra:supportedClass'], static fn (array $class): bool => $class['hydra:title'] === $type))[0];
            $property = array_values(array_filter($class['hydra:supportedProperty'], static fn (array $property): bool => $property['hydra:title'] === $column))[0];
            self::assertSame('rdf:Property', $property['hydra:property']['@type'], $type . '.' . $column);
            self::assertSame('xsd:integer', $property['hydra:property']['range'], $type . '.' . $column);
        }
    }

    public function testHydraDescribesAnEmbeddedGroupOverOneTableWithoutALinkAsAResource(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_testrecordtypes_item')
            ->insert('tx_testrecordtypes_item', ['uid' => 710, 'pid' => 1, 'item_type' => 'article', 'title' => 'Item', 'target_page' => '500']);
        $this->registerResource('items', [
            'general'  => ['table' => 'tx_testrecordtypes_item', 'resourceName' => 'items', 'resourceType' => 'Item'],
            'columns'  => ['target_page' => ['groups' => ['show'], 'embed' => true]],
            'security' => ['list' => AccessRole::PUBLIC, 'show' => AccessRole::PUBLIC],
        ]);

        $target = $this->decodeResponseBody($this->executeApiRequest('/_api/items/710'))['target_page'];
        self::assertSame(['WebPage', 500], [$target['@type'], $target['uid']]);
        self::assertArrayNotHasKey('@id', $target);

        $hydra    = $this->decodeResponseBody($this->executeApiRequest('/_api/docs.jsonld'));
        $class    = array_values(array_filter($hydra['hydra:supportedClass'], static fn (array $class): bool => $class['hydra:title'] === 'Item'))[0];
        $property = array_values(array_filter($class['hydra:supportedProperty'], static fn (array $property): bool => $property['hydra:title'] === 'target_page'))[0];
        self::assertSame('rdf:Property', $property['hydra:property']['@type']);
        self::assertSame('rdfs:Resource', $property['hydra:property']['range']);
    }

    public function testAManyToOneGroupOverSeveralTablesKeepsItsRecord(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_testrecordtypes_item')
            ->insert('tx_testrecordtypes_item', ['uid' => 710, 'pid' => 1, 'item_type' => 'article', 'title' => 'Item', 'target' => 'pages_500']);
        $this->registerResource('items', [
            'general'  => ['table' => 'tx_testrecordtypes_item', 'resourceName' => 'items', 'resourceType' => 'Item'],
            'columns'  => ['target' => ['groups' => ['show']]],
            'security' => ['list' => AccessRole::PUBLIC, 'show' => AccessRole::PUBLIC],
        ]);

        $target = $this->decodeResponseBody($this->executeApiRequest('/_api/items/710'))['target'];
        $schema = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'))['components']['schemas']['ItemRead']['properties']['target'];

        self::assertSame('array', $schema['type']);
        // A polymorphic group names no resource, and web-pages serves only some doktypes.
        self::assertSame([['@type' => 'WebPage', 'uid' => 500]], $target);
    }
}
