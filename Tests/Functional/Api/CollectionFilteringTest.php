<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for collection filter query parameters.
 *
 * Filter syntax: ?filters[column]=value (exact match)
 *
 * RED phase: RequestDispatcher/GetCollectionHandler ignore filters — all tests must fail initially.
 */
final class CollectionFilteringTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    public function testFilterByExactTitleReturnsSingleMember(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['title' => 'First Article']]);
        $body = $this->decodeResponseBody($response);

        self::assertCount(1, $body['hydra:member']);
        self::assertSame('First Article', $body['hydra:member'][0]['title']);
    }

    public function testFilterByExactTitleUpdatesTotalItems(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['title' => 'First Article']]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
    }

    public function testFilterWithNoMatchReturnsEmptyCollection(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['title' => 'Nonexistent']]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(0, $body['hydra:totalItems']);
        self::assertCount(0, $body['hydra:member']);
    }

    public function testFilterOnUndeclaredColumnIsIgnored(): void
    {
        // 'deleted' is not declared as a filterable column — must not expose deleted records
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['deleted' => '0']]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $body['hydra:totalItems']);
    }

    public function testFilterRetainsHiddenExclusion(): void
    {
        // Even when filtering, hidden=1 records must stay excluded
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['title' => 'Hidden Article']]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(0, $body['hydra:totalItems']);
    }
}
