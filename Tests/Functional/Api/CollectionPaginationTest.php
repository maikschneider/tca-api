<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

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
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    public function testItemsPerPageLimitsMemberCount(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['itemsPerPage' => 2]);
        $body = $this->decodeResponseBody($response);

        self::assertCount(2, $body['hydra:member']);
    }

    public function testTotalItemsIsUnaffectedByItemsPerPage(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['itemsPerPage' => 2]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(3, $body['hydra:totalItems']);
    }

    public function testPageTwoReturnsRemainingItems(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['page' => 2, 'itemsPerPage' => 2]);
        $body = $this->decodeResponseBody($response);

        self::assertCount(1, $body['hydra:member']);
        self::assertSame('Third Article', $body['hydra:member'][0]['title']);
    }

    public function testHydraViewIsPresentOnPagedResponse(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['itemsPerPage' => 2]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('hydra:view', $body);
        self::assertSame('hydra:PartialCollectionView', $body['hydra:view']['@type']);
    }

    public function testHydraViewContainsNextLink(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['page' => 1, 'itemsPerPage' => 2]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('hydra:next', $body['hydra:view']);
        self::assertNotNull($body['hydra:view']['hydra:next']);
    }

    public function testHydraViewHasNoNextLinkOnLastPage(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['page' => 2, 'itemsPerPage' => 2]);
        $body = $this->decodeResponseBody($response);

        self::assertNull($body['hydra:view']['hydra:next'] ?? null);
    }
}
