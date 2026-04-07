<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for write operations: POST, PUT, PATCH, DELETE.
 *
 * RED phase: RequestDispatcher only handles GET — all tests must fail initially.
 */
final class WriteOperationsTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    // ── POST ─────────────────────────────────────────────────────────────────

    public function testPostReturns201(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/articles', [
            'title' => 'New Article',
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testPostResponseContainsCreatedResource(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/articles', [
            'title' => 'New Article',
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame('Article', $body['@type']);
        self::assertSame('New Article', $body['title']);
        self::assertArrayHasKey('uid', $body);
    }

    public function testPostPersistsRecordInDatabase(): void
    {
        $this->executeApiWriteRequest('POST', '/_api/articles', ['title' => 'Persisted Article']);

        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['title' => 'Persisted Article']]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
    }

    public function testPostReturns422ForMissingTitle(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/articles', []);

        self::assertSame(422, $response->getStatusCode());
    }

    // ── PUT ──────────────────────────────────────────────────────────────────

    public function testPutReturns200(): void
    {
        $response = $this->executeApiWriteRequest('PUT', '/_api/articles/1', [
            'title' => 'Updated Article',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPutUpdatesRecord(): void
    {
        $this->executeApiWriteRequest('PUT', '/_api/articles/1', ['title' => 'Updated Title']);

        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame('Updated Title', $body['title']);
    }

    public function testPutReturns404ForMissingRecord(): void
    {
        $response = $this->executeApiWriteRequest('PUT', '/_api/articles/999', ['title' => 'Ghost']);

        self::assertSame(404, $response->getStatusCode());
    }

    // ── PATCH ────────────────────────────────────────────────────────────────

    public function testPatchReturns200(): void
    {
        $response = $this->executeApiWriteRequest('PATCH', '/_api/articles/1', [
            'title' => 'Patched Title',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPatchUpdatesOnlySuppliedFields(): void
    {
        $this->executeApiWriteRequest('PATCH', '/_api/articles/2', ['title' => 'Patched Second']);

        $response = $this->executeApiRequest('/_api/articles/2');
        $body = $this->decodeResponseBody($response);

        self::assertSame('Patched Second', $body['title']);
        self::assertSame(2, $body['uid']);
    }

    // ── DELETE ───────────────────────────────────────────────────────────────

    public function testDeleteReturns204(): void
    {
        $response = $this->executeApiWriteRequest('DELETE', '/_api/articles/3');

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDeletedRecordIsNoLongerReachable(): void
    {
        $this->executeApiWriteRequest('DELETE', '/_api/articles/3');

        $response = $this->executeApiRequest('/_api/articles/3');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testDeletedRecordIsExcludedFromCollection(): void
    {
        $this->executeApiWriteRequest('DELETE', '/_api/articles/3');

        $response = $this->executeApiRequest('/_api/articles');
        $body = $this->decodeResponseBody($response);

        self::assertSame(2, $body['hydra:totalItems']);
    }

    public function testDeleteReturns404ForMissingRecord(): void
    {
        $response = $this->executeApiWriteRequest('DELETE', '/_api/articles/999');

        self::assertSame(404, $response->getStatusCode());
    }

    // ── 405 Method Not Allowed ───────────────────────────────────────────────

    public function testUnsupportedMethodReturns405(): void
    {
        $response = $this->executeApiWriteRequest('OPTIONS', '/_api/articles');

        self::assertSame(405, $response->getStatusCode());
    }
}
