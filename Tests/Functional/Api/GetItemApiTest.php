<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for the single-item (show) endpoint.
 *
 * RED phase: GetItemHandler returns 501 — all tests must fail initially.
 */
final class GetItemApiTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    public function testGetItemReturnsValidResource(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'application/ld+json',
            $response->getHeaderLine('Content-Type'),
        );
        self::assertSame('Article', $body['@type']);
        self::assertSame(1, $body['uid']);
        self::assertSame('First Article', $body['title']);
    }

    public function testGetItemReturns404ForMissingRecord(): void
    {
        $response = $this->executeApiRequest('/_api/articles/999');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testGetItemReturns404ForHiddenRecord(): void
    {
        // uid=4 is hidden=1 (seeded in articles.csv)
        $response = $this->executeApiRequest('/_api/articles/4');

        self::assertSame(404, $response->getStatusCode());
    }
}
