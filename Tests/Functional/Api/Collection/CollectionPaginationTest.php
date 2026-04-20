<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Collection;

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
