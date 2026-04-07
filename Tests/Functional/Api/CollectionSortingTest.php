<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for collection ordering query parameters.
 *
 * Order syntax: ?order[column]=asc|desc
 *
 * RED phase: GetCollectionHandler ignores order params — all tests must fail initially.
 */
final class CollectionSortingTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    public function testOrderByTitleAscending(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['order' => ['title' => 'asc']]);
        $body = $this->decodeResponseBody($response);

        $titles = array_column($body['hydra:member'], 'title');
        self::assertSame(['First Article', 'Second Article', 'Third Article'], $titles);
    }

    public function testOrderByTitleDescending(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['order' => ['title' => 'desc']]);
        $body = $this->decodeResponseBody($response);

        $titles = array_column($body['hydra:member'], 'title');
        self::assertSame(['Third Article', 'Second Article', 'First Article'], $titles);
    }

    public function testDefaultOrderIsByUidAscending(): void
    {
        // Without an order param the config default applies: uid ASC
        $response = $this->executeApiRequest('/_api/articles');
        $body = $this->decodeResponseBody($response);

        $uids = array_column($body['hydra:member'], 'uid');
        self::assertSame([1, 2, 3], $uids);
    }

    public function testOrderOnUndeclaredColumnIsIgnored(): void
    {
        // 'deleted' is not declared sortable — must not throw, must return 200
        $response = $this->executeApiRequest('/_api/articles', ['order' => ['deleted' => 'asc']]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $body['hydra:totalItems']);
    }
}
