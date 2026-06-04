<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * Functional tests for the HTTP response cache in RequestDispatcher.
 * Registers a dedicated 'cached-articles' resource (backed by the same
 * tx_myext_domain_model_article fixture table) so tests can toggle cache
 * settings without affecting other test classes.
 */
final class CacheTest extends ApiFunctionalTestCase
{
    private const RESOURCE = 'cached-articles';
    private const TABLE = 'tx_myext_domain_model_article';
    private const PATH_COLLECTION = '/_api/cached-articles';
    private const PATH_ITEM = '/_api/cached-articles/1';

    private const BASE_CONFIG = [
        'general' => [
            'table' => self::TABLE,
            'resourceName' => self::RESOURCE,
            'resourceType' => 'Article',
            'operations' => ['list', 'show', 'create'],
        ],
        'columns' => [
            'title' => ['type' => 'string', 'groups' => ['list', 'show']],
        ],
        'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
    ];

    private const BASE_CONFIG_WITH_FILTERS = [
        'general' => [
            'table' => self::TABLE,
            'resourceName' => self::RESOURCE,
            'resourceType' => 'Article',
            'operations' => ['list', 'show'],
        ],
        'columns' => [
            'title' => ['type' => 'string', 'groups' => ['list', 'show']],
            'color_id' => ['groups' => ['list', 'show']],
        ],
        'filters' => [
            'color_id' => ExactFilter::class,
        ],
        'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
    ];

    public function testFirstCollectionRequestReturnsMissHeader(): void
    {
        $this->registerCachedResource();

        $response = $this->executeApiRequest(self::PATH_COLLECTION);

        self::assertSame('MISS', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    // ── MISS / HIT cycle — collection ────────────────────────────────────────

    private function registerCachedResource(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'cache' => ['enabled' => true, 'lifetime' => 3600],
        ]));
    }

    public function testSecondCollectionRequestReturnsHitHeader(): void
    {
        $this->registerCachedResource();

        $this->executeApiRequest(self::PATH_COLLECTION); // warm the cache
        $response = $this->executeApiRequest(self::PATH_COLLECTION);

        self::assertSame('HIT', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    public function testHitResponseBodyMatchesMissResponseBody(): void
    {
        $this->registerCachedResource();

        $miss = $this->executeApiRequest(self::PATH_COLLECTION);
        $hit = $this->executeApiRequest(self::PATH_COLLECTION);

        self::assertSame((string)$miss->getBody(), (string)$hit->getBody());
    }

    // ── X-Cache-Tags ─────────────────────────────────────────────────────────

    public function testMissResponseContainsCacheTags(): void
    {
        $this->registerCachedResource();

        $response = $this->executeApiRequest(self::PATH_COLLECTION);

        $tags = $response->getHeaderLine('X-Cache-Tags');
        self::assertNotEmpty($tags);
        // Three visible articles → three tags (UIDs 1, 2, 3)
        self::assertStringContainsString(self::TABLE . '_1', $tags);
        self::assertStringContainsString(self::TABLE . '_2', $tags);
        self::assertStringContainsString(self::TABLE . '_3', $tags);
    }

    public function testHitResponseDoesNotContainCacheTags(): void
    {
        $this->registerCachedResource();

        $this->executeApiRequest(self::PATH_COLLECTION); // MISS — tags emitted
        $response = $this->executeApiRequest(self::PATH_COLLECTION); // HIT

        self::assertSame('', $response->getHeaderLine('X-Cache-Tags'));
    }

    // ── Cache disabled ────────────────────────────────────────────────────────

    public function testDisabledCacheEmitsNoCacheHeader(): void
    {
        $this->registerResource(self::RESOURCE, self::BASE_CONFIG);

        $first = $this->executeApiRequest(self::PATH_COLLECTION);
        $second = $this->executeApiRequest(self::PATH_COLLECTION);

        self::assertSame('', $first->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('', $second->getHeaderLine('X-TCA-API-Cache'));
    }

    // ── parametersToIgnore bypass ─────────────────────────────────────────────

    public function testIgnoredTopLevelParamBypassesCache(): void
    {
        $this->registerPreviewBypassResource();

        $response = $this->executeApiRequest(self::PATH_COLLECTION, ['preview' => '1']);

        self::assertSame('', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    private function registerPreviewBypassResource(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'cache' => ['enabled' => true, 'lifetime' => 3600, 'parametersToIgnore' => ['preview']],
        ]));
    }

    public function testIgnoredParamNestedUnderFiltersAlsoBypassesCache(): void
    {
        $this->registerPreviewBypassResource();

        $response = $this->executeApiRequest(self::PATH_COLLECTION, ['filters' => ['preview' => '1']]);

        self::assertSame('', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    // ── Separate cache keys per param set ────────────────────────────────────

    public function testRequestWithoutIgnoredParamIsCached(): void
    {
        $this->registerPreviewBypassResource();

        $response = $this->executeApiRequest(self::PATH_COLLECTION);

        self::assertSame('MISS', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    // ── Show (item) operation ─────────────────────────────────────────────────

    public function testDifferentQueryParamsProduceSeparateCacheEntries(): void
    {
        $this->registerCachedResource();

        // Warm cache for page 1
        $this->executeApiRequest(self::PATH_COLLECTION, ['page' => '1']);
        // page 2 should be a MISS, not a HIT
        $response = $this->executeApiRequest(self::PATH_COLLECTION, ['page' => '2']);

        self::assertSame('MISS', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    public function testFirstItemRequestReturnsMissHeader(): void
    {
        $this->registerCachedResource();

        $response = $this->executeApiRequest(self::PATH_ITEM);

        self::assertSame('MISS', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    // ── Write operations are not cached ──────────────────────────────────────

    public function testSecondItemRequestReturnsHitHeader(): void
    {
        $this->registerCachedResource();

        $this->executeApiRequest(self::PATH_ITEM);
        $response = $this->executeApiRequest(self::PATH_ITEM);

        self::assertSame('HIT', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    // ── Top-level filter param cache keys ─────────────────────────────────────

    public function testTopLevelFilterParamProducesCacheMissOnFirstRequest(): void
    {
        $this->registerCachedResourceWithFilters();

        $response = $this->executeApiRequest(self::PATH_COLLECTION, ['color_id' => '1']);

        self::assertSame('MISS', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    public function testTopLevelFilterParamProducesCacheHitOnSecondIdenticalRequest(): void
    {
        $this->registerCachedResourceWithFilters();

        $this->executeApiRequest(self::PATH_COLLECTION, ['color_id' => '1']);
        $response = $this->executeApiRequest(self::PATH_COLLECTION, ['color_id' => '1']);

        self::assertSame('HIT', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    public function testTopLevelAndBracketNotationProduceSameCacheKey(): void
    {
        $this->registerCachedResourceWithFilters();

        // Warm cache using top-level param style
        $this->executeApiRequest(self::PATH_COLLECTION, ['color_id' => '1']);
        // Second request using bracket-notation — must resolve to same cache key → HIT
        $response = $this->executeApiRequest(self::PATH_COLLECTION, ['filters' => ['color_id' => '1']]);

        self::assertSame('HIT', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    public function testDifferentTopLevelFilterValuesProduceDifferentCacheEntries(): void
    {
        $this->registerCachedResourceWithFilters();

        $this->executeApiRequest(self::PATH_COLLECTION, ['color_id' => '1']);
        $response = $this->executeApiRequest(self::PATH_COLLECTION, ['color_id' => '2']);

        self::assertSame('MISS', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    private function registerCachedResourceWithFilters(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG_WITH_FILTERS, [
            'cache' => ['enabled' => true, 'lifetime' => 3600],
        ]));
    }

    // ── X-Cache-Tag-Count header ──────────────────────────────────────────────

    public function testMissResponseIncludesCacheTagCountHeader(): void
    {
        $this->registerCachedResource();

        $response = $this->executeApiRequest(self::PATH_COLLECTION);

        // 1 table-level tag + 3 visible article tags (UIDs 1–3; UID 4 is hidden)
        self::assertSame('4', $response->getHeaderLine('X-Cache-Tag-Count'));
    }

    public function testMissResponseOmitsTagsHeaderWhenTagsTotalExceedsLimit(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'cache' => ['enabled' => true, 'lifetime' => 3600],
            'general' => array_merge(self::BASE_CONFIG['general'], ['itemsPerPage' => 150]),
        ]));
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles_cache_tags_overflow.csv');

        $response = $this->executeApiRequest(self::PATH_COLLECTION);

        self::assertSame('MISS', $response->getHeaderLine('X-TCA-API-Cache'));
        self::assertSame('', $response->getHeaderLine('X-Cache-Tags'));
    }

    public function testCacheTagCountReflectsActualTagCountWhenTagsExceedLimit(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'cache' => ['enabled' => true, 'lifetime' => 3600],
            'general' => array_merge(self::BASE_CONFIG['general'], ['itemsPerPage' => 150]),
        ]));
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles_cache_tags_overflow.csv');

        $response = $this->executeApiRequest(self::PATH_COLLECTION);

        // 1 table-level tag + 3 visible from articles.csv (UIDs 1–3) + 118 from overflow fixture
        self::assertSame('122', $response->getHeaderLine('X-Cache-Tag-Count'));
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    public function testWriteOperationEmitsNoCacheHeader(): void
    {
        $this->registerCachedResource();

        $response = $this->executeApiWriteRequest('POST', self::PATH_COLLECTION, ['title' => 'New Article']);

        self::assertSame('', $response->getHeaderLine('X-TCA-API-Cache'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');

        // Flush the cache between tests so each test starts with a cold cache.
        $this->get(CacheManager::class)->getCache('tca_api')->flush();
    }
}
