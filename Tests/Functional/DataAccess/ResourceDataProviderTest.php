<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\DataAccess;

use MaikSchneider\TcaApi\DataAccess\CollectionQuery;
use MaikSchneider\TcaApi\DataAccess\ItemQuery;
use MaikSchneider\TcaApi\DataAccess\ResourceDataProvider;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for {@see ResourceDataProvider} — the request-free read pipeline
 * shared by the HTTP handlers and the frontend data layer.
 *
 * Uses the articles fixture (3 visible records, uid 1-3; uid 4 is hidden).
 */
final class ResourceDataProviderTest extends ApiFunctionalTestCase
{
    private ResourceDataProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/colors.csv');
        $this->provider = $this->get(ResourceDataProvider::class);
    }

    public function testGetCollectionReturnsVisibleRecordsWithJsonLdEnvelope(): void
    {
        $config = $this->getApiRegistry()->get('articles');
        self::assertNotNull($config);

        $result = $this->provider->getCollection($config, new CollectionQuery(fields: ['title', 'color_id']));

        self::assertCount(3, $result->members);
        self::assertSame(3, $result->total);
        self::assertSame(1, $result->page);
        self::assertSame(20, $result->itemsPerPage);
        self::assertSame(1, $result->totalPages());

        $first = $result->members[0];
        self::assertSame('Article', $first['@type']);
        self::assertArrayHasKey('@id', $first);
        self::assertSame(1, $first['uid']);
        self::assertSame('First Article', $first['title']);
    }

    public function testGetItemReturnsSingleRecordOrNull(): void
    {
        $config = $this->getApiRegistry()->get('articles');
        self::assertNotNull($config);

        $item = $this->provider->getItem($config, 2, new ItemQuery(fields: ['title']));
        self::assertNotNull($item);
        self::assertSame(2, $item['uid']);
        self::assertSame('Second Article', $item['title']);
        self::assertSame('Article', $item['@type']);

        self::assertNull($this->provider->getItem($config, 999, new ItemQuery(fields: ['title'])));
    }

    public function testGetCollectionAppliesFilterAndOrder(): void
    {
        $config = $this->getApiRegistry()->get('articles');
        self::assertNotNull($config);

        // Filter: only "Third Article"
        $filtered = $this->provider->getCollection($config, new CollectionQuery(
            filters: ['title' => 'Third Article'],
            fields:  ['title'],
        ));
        self::assertCount(1, $filtered->members);
        self::assertSame(3, $filtered->members[0]['uid']);

        // Order: title descending → Third, Second, First
        $ordered = $this->provider->getCollection($config, new CollectionQuery(
            order:  ['title' => 'desc'],
            fields: ['title'],
        ));
        self::assertSame(
            ['Third Article', 'Second Article', 'First Article'],
            array_column($ordered->members, 'title'),
        );
    }

    public function testItemsPerPageIsClampedToConfiguredMax(): void
    {
        $this->registerResource('capped-articles', [
            'general' => [
                'table'           => 'tx_myext_domain_model_article',
                'resourceName'    => 'capped-articles',
                'resourceType'    => 'Article',
                'operations'      => ['list', 'show'],
                'storagePid'      => 1,
                'maxItemsPerPage' => 2,
            ],
            'columns' => ['title' => ['groups' => ['list', 'show']]],
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
        $config = $this->getApiRegistry()->get('capped-articles');
        self::assertNotNull($config);

        $result = $this->provider->getCollection($config, new CollectionQuery(itemsPerPage: 50, fields: ['title']));

        self::assertCount(2, $result->members);   // clamped to max
        self::assertSame(2, $result->itemsPerPage); // resolved value reflects the clamp
        self::assertSame(3, $result->total);        // total is unaffected by clamping
    }
}
