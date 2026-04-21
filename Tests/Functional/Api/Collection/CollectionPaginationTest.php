<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Collection;

use MaikSchneider\TcaApi\Filter\PartialFilter;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Functional tests for collection pagination.
 *
 * RED phase: RequestDispatcher ignores page/itemsPerPage — all tests must fail initially.
 */
final class CollectionPaginationTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
    }

    public function testFirstPageReturnsCorrectHydraView(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['itemsPerPage' => 2]);
        $body = $this->decodeResponseBody($response);

        self::assertCount(2, $body['hydra:member']);
        self::assertSame(3, $body['hydra:totalItems']);
        self::assertArrayHasKey('hydra:view', $body);
        self::assertSame('hydra:PartialCollectionView', $body['hydra:view']['@type']);
        self::assertArrayHasKey('hydra:next', $body['hydra:view']);
        self::assertNotNull($body['hydra:view']['hydra:next']);
    }

    public function testLastPageReturnsRemainingItemsWithoutNextLink(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['page' => 2, 'itemsPerPage' => 2]);
        $body = $this->decodeResponseBody($response);

        self::assertCount(1, $body['hydra:member']);
        self::assertSame('Third Article', $body['hydra:member'][0]['title']);
        self::assertNull($body['hydra:view']['hydra:next'] ?? null);
    }

    public static function invalidItemsPerPageProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
    }

    public function testPaginationLinksPreserveQueryState(): void
    {
        // Register a resource with partial title filter and sort by title
        $this->registerResource('paginated-articles', [
            'general' => [
                'table' => 'tx_myext_domain_model_article',
                'resourceName' => 'paginated-articles',
                'resourceType' => 'Article',
                'operations' => ['list'],
            ],
            'columns' => ['title' => ['type' => 'string', 'groups' => ['list']]],
            'filters' => ['title' => PartialFilter::class],
            'order' => ['allowed' => ['title'], 'default' => ['title' => 'asc']],
        ]);

        // "rticle" matches all 3 articles as a substring — 3 pages at itemsPerPage=1
        $response = $this->executeApiRequest('/_api/paginated-articles', [
            'itemsPerPage' => 1,
            'order' => ['title' => 'asc'],
            'filters' => ['title' => 'rticle'],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $body['hydra:totalItems']);
        self::assertNotNull($body['hydra:view']['hydra:next']);

        parse_str((string)parse_url($body['hydra:view']['hydra:next'], PHP_URL_QUERY), $nextParams);

        self::assertSame('2', $nextParams['page']);
        self::assertSame('1', $nextParams['itemsPerPage']);
        self::assertSame('asc', $nextParams['order']['title'] ?? null);
        self::assertSame('rticle', $nextParams['filters']['title'] ?? null);

        parse_str((string)parse_url($body['hydra:view']['hydra:first'], PHP_URL_QUERY), $firstParams);
        self::assertSame('1', $firstParams['page']);
        self::assertSame('asc', $firstParams['order']['title'] ?? null);

        parse_str((string)parse_url($body['hydra:view']['hydra:last'], PHP_URL_QUERY), $lastParams);
        self::assertSame('3', $lastParams['page']);
        self::assertSame('asc', $lastParams['order']['title'] ?? null);
    }

    #[DataProvider('invalidItemsPerPageProvider')]
    public function testInvalidItemsPerPageIsClampedToOne(int $invalidValue): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['itemsPerPage' => $invalidValue]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('hydra:member', $body);
        self::assertCount(1, $body['hydra:member']);
        self::assertSame(3, $body['hydra:totalItems']);
        self::assertStringContainsString('itemsPerPage=1', $body['hydra:view']['hydra:first']);
    }
}
