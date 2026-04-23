<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for the storagePid config constraint.
 *
 * Fixture records (articles_multipid.csv):
 *   uid=100 → pid=42  (Pid42 Article A)
 *   uid=101 → pid=42  (Pid42 Article B)
 *   uid=102 → pid=99  (Pid99 Article — must be invisible)
 *
 * Resource registered with 'storagePid' => 42.
 */
final class StoragePidTest extends ApiFunctionalTestCase
{
    private const RESOURCE = 'pid-articles';

    private const CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => self::RESOURCE,
            'resourceType' => 'Article',
            'operations'   => ['list', 'show'],
            'storagePid'   => 42,
        ],
        'columns' => [
            'title' => ['groups' => ['list', 'show']],
        ],
        'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles_multipid.csv');
        $this->registerResource(self::RESOURCE, self::CONFIG);
    }

    // ── Collection ────────────────────────────────────────────────────────────

    public function testCollectionOnlyReturnsPid42Records(): void
    {
        $response = $this->executeApiRequest('/_api/' . self::RESOURCE);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponseBody($response);
        $uids = array_column($body['hydra:member'], 'uid');
        self::assertContains(100, $uids);
        self::assertContains(101, $uids);
        self::assertNotContains(102, $uids);
    }

    public function testCollectionCountReflectsPidConstraint(): void
    {
        $response = $this->executeApiRequest('/_api/' . self::RESOURCE);
        $body = $this->decodeResponseBody($response);

        self::assertSame(2, $body['hydra:totalItems']);
    }

    // ── Item ──────────────────────────────────────────────────────────────────

    public function testShowReturns200ForRecordOnCorrectPid(): void
    {
        $response = $this->executeApiRequest('/_api/' . self::RESOURCE . '/100');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testShowReturns404ForRecordOnWrongPid(): void
    {
        $response = $this->executeApiRequest('/_api/' . self::RESOURCE . '/102');

        self::assertSame(404, $response->getStatusCode());
    }
}
