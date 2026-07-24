<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * Functional tests verifying that API write operations (create/update/delete)
 * properly invalidate cached API responses.
 */
final class CacheWriteInvalidationTest extends ApiFunctionalTestCase
{
    private const RESOURCE = 'cached-articles-write';
    private const TABLE = 'tx_myext_domain_model_article';
    private const PATH_COLLECTION = '/_api/cached-articles-write';
    private const PATH_ITEM = '/_api/cached-articles-write/1';

    private const CONFIG = [
        'general' => [
            'table' => self::TABLE,
            'resourceName' => self::RESOURCE,
            'resourceType' => 'Article',
            'operations' => ['list', 'show', 'create', 'update', 'delete'],
            'storagePid' => 1,
        ],
        'columns' => [
            'title' => ['type' => 'string', 'groups' => ['list', 'show', 'create', 'update'], 'required' => true],
        ],
        'security' => [
            'list'   => AccessRole::PUBLIC,
            'show'   => AccessRole::PUBLIC,
            'create' => AccessRole::PUBLIC,
            'update' => AccessRole::PUBLIC,
            'delete' => AccessRole::PUBLIC,
        ],
        'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');

        // Flush the cache between tests so each test starts with a cold cache.
        $this->get(CacheManager::class)->getCache('tca_api')->flush();
    }

    /**
     * Verifies that after creating a record via API, the cached collection is
     * invalidated and the next GET request reflects the new record.
     */
    public function testCreateInvalidatesCachedListResponse(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::CONFIG, [
            'cache' => ['enabled' => true, 'lifetime' => 3600],
        ]));

        // Step 1: Warm the cache with a list request
        $first = $this->executeApiRequest(self::PATH_COLLECTION);
        self::assertSame('MISS', $first->getHeaderLine('X-TCA-API-Cache'));
        $firstBody = (string)$first->getBody();
        self::assertStringContainsString('First Article', $firstBody);

        // Step 2: Create a new record via API
        $createResponse = $this->executeApiWriteRequest('POST', self::PATH_COLLECTION, ['title' => 'Created by API']);
        self::assertSame(201, $createResponse->getStatusCode(), 'Create failed: ' . (string)$createResponse->getBody());

        // Step 3: Re-fetch the list — must be fresh (not stale)
        $second = $this->executeApiRequest(self::PATH_COLLECTION);
        $secondBody = (string)$second->getBody();
        $cacheHeader = $second->getHeaderLine('X-TCA-API-Cache');

        self::assertStringContainsString('Created by API', $secondBody, 'Created record not visible in list — cache invalidation failed after CREATE (cache header: ' . $cacheHeader . ')');
    }

    /**
     * Verifies that after updating a record via API, the cached item and list
     * responses are invalidated.
     */
    public function testUpdateInvalidatesCachedShowResponse(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::CONFIG, [
            'cache' => ['enabled' => true, 'lifetime' => 3600],
        ]));

        // Warm cache for /show/1
        $this->executeApiRequest(self::PATH_ITEM);
        $first = $this->executeApiRequest(self::PATH_ITEM);
        self::assertSame('HIT', $first->getHeaderLine('X-TCA-API-Cache'));

        // Update record 1
        $updateResponse = $this->executeApiWriteRequest('PUT', self::PATH_ITEM, ['title' => 'Updated Title']);
        self::assertSame(200, $updateResponse->getStatusCode());

        // Re-fetch — must show updated title
        $second = $this->executeApiRequest(self::PATH_ITEM);
        $secondBody = (string)$second->getBody();
        self::assertStringContainsString('Updated Title', $secondBody, 'Updated title not visible — cache invalidation failed after UPDATE');
    }

    /**
     * Verifies that after deleting a record via API, the cached list response
     * is invalidated.
     */
    public function testDeleteInvalidatesCachedListResponse(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::CONFIG, [
            'cache' => ['enabled' => true, 'lifetime' => 3600],
        ]));

        // Warm cache
        $this->executeApiRequest(self::PATH_COLLECTION);
        $first = $this->executeApiRequest(self::PATH_COLLECTION);
        self::assertSame('HIT', $first->getHeaderLine('X-TCA-API-Cache'));
        self::assertStringContainsString('First Article', (string)$first->getBody());

        // Delete record 1
        $deleteResponse = $this->executeApiWriteRequest('DELETE', self::PATH_ITEM);
        self::assertSame(204, $deleteResponse->getStatusCode());

        // Re-fetch — must no longer show First Article
        $second = $this->executeApiRequest(self::PATH_COLLECTION);
        $secondBody = (string)$second->getBody();
        self::assertStringNotContainsString('First Article', $secondBody, 'Deleted record still visible — cache invalidation failed after DELETE');
    }

    /**
     * Regression: an empty collection response (zero serialized rows) must still
     * carry the base '{table}' cache tag, otherwise the entry can never be
     * flushed by a later write and stays stale until its lifetime expires.
     */
    public function testEmptyCollectionResponseCarriesTableTag(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_empty_storage.csv');
        $this->registerResource(self::RESOURCE, array_merge(self::CONFIG, [
            'general' => array_merge(self::CONFIG['general'], ['storagePid' => 2]),
            'cache' => ['enabled' => true, 'lifetime' => 3600],
        ]));

        // pid 2 holds no articles → collection is empty.
        $response = $this->executeApiRequest(self::PATH_COLLECTION);

        self::assertSame('MISS', $response->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('1', $response->getHeaderLine('X-Cache-Tag-Count'));
        self::assertSame(self::TABLE, $response->getHeaderLine('X-Cache-Tags'));
    }

    /**
     * Regression: a cached empty collection must be invalidated when a record is
     * created, so the newly created record becomes visible instead of a stale
     * empty list being served from cache.
     */
    public function testCreateInvalidatesCachedEmptyListResponse(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_empty_storage.csv');
        $this->registerResource(self::RESOURCE, array_merge(self::CONFIG, [
            'general' => array_merge(self::CONFIG['general'], ['storagePid' => 2]),
            'cache' => ['enabled' => true, 'lifetime' => 3600],
        ]));

        // Warm the cache with an empty collection, then confirm it is cached.
        $this->executeApiRequest(self::PATH_COLLECTION);
        $warm = $this->executeApiRequest(self::PATH_COLLECTION);
        self::assertSame('HIT', $warm->getHeaderLine('X-TCA-API-Cache'));

        // Create a record on the same storage pid.
        $createResponse = $this->executeApiWriteRequest('POST', self::PATH_COLLECTION, ['title' => 'First in empty list']);
        self::assertSame(201, $createResponse->getStatusCode(), 'Create failed: ' . (string)$createResponse->getBody());

        // Re-fetch — the empty entry must have been flushed and the new record shown.
        $second = $this->executeApiRequest(self::PATH_COLLECTION);
        self::assertSame('MISS', $second->getHeaderLine('X-TCA-API-Cache'), 'Empty collection cache was not invalidated after CREATE');
        self::assertStringContainsString('First in empty list', (string)$second->getBody());
    }

    /**
     * Verify that cache invalidation only happens for resources with cache enabled.
     */
    public function testWriteOnUncachedResourceDoesNotCrash(): void
    {
        $this->registerResource(self::RESOURCE, self::CONFIG); // no cache key

        $response = $this->executeApiWriteRequest('POST', self::PATH_COLLECTION, ['title' => 'No Cache']);
        self::assertSame(201, $response->getStatusCode(), 'Create failed: ' . (string)$response->getBody());
    }
}
