<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for write operations: POST, PUT, PATCH, DELETE.
 */
final class WriteOperationsTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
    }

    // ── POST ─────────────────────────────────────────────────────────────────

    public function testPostCreatesResourceAndReturns201(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'New Article',
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Article', $body['@type']);
        self::assertSame('New Article', $body['title']);
        self::assertArrayHasKey('uid', $body);
    }

    public function testPostPersistsRecordInDatabase(): void
    {
        $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, ['title' => 'Persisted Article']);

        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['title' => 'Persisted Article']]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
    }

    public function testPostReturns422ForMissingTitle(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, []);

        self::assertSame(422, $response->getStatusCode());
    }

    // ── PUT ──────────────────────────────────────────────────────────────────

    public function testPutReturns200(): void
    {
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, [
            'title' => 'Updated Article',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPutUpdatesRecord(): void
    {
        $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, ['title' => 'Updated Title']);

        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame('Updated Title', $body['title']);
    }

    public function testPutReturns404ForMissingRecord(): void
    {
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/articles/999', 1, ['title' => 'Ghost']);

        self::assertSame(404, $response->getStatusCode());
    }

    // ── PATCH ────────────────────────────────────────────────────────────────

    public function testPatchReturns200(): void
    {
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/articles/1', 1, [
            'title' => 'Patched Title',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPatchUpdatesOnlySuppliedFields(): void
    {
        $this->executeApiWriteRequestAs('PATCH', '/_api/articles/2', 1, ['title' => 'Patched Second']);

        $response = $this->executeApiRequest('/_api/articles/2');
        $body = $this->decodeResponseBody($response);

        self::assertSame('Patched Second', $body['title']);
        self::assertSame(2, $body['uid']);
    }

    // ── DELETE ───────────────────────────────────────────────────────────────

    public function testDeleteReturns204(): void
    {
        $response = $this->executeApiWriteRequestAsBackendUser('DELETE', '/_api/articles/3', 1);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDeletedRecordIsNoLongerReachable(): void
    {
        $this->executeApiWriteRequestAsBackendUser('DELETE', '/_api/articles/3', 1);

        $response = $this->executeApiRequest('/_api/articles/3');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testDeletedRecordIsExcludedFromCollection(): void
    {
        $this->executeApiWriteRequestAsBackendUser('DELETE', '/_api/articles/3', 1);

        $response = $this->executeApiRequest('/_api/articles');
        $body = $this->decodeResponseBody($response);

        self::assertSame(2, $body['hydra:totalItems']);
    }

    public function testDeleteReturns404ForMissingRecord(): void
    {
        $response = $this->executeApiWriteRequestAsBackendUser('DELETE', '/_api/articles/999', 1);

        self::assertSame(404, $response->getStatusCode());
    }

    // ── Location header on POST ───────────────────────────────────────────────

    public function testPostResponseHasCorrectLocationHeader(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'New Article',
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertTrue($response->hasHeader('Location'));
        self::assertSame('/_api/articles/' . $body['uid'], $response->getHeaderLine('Location'));
    }

    // ── Malformed JSON body ──────────────────────────────────────────────────

    public function testPostWithMalformedJsonReturns400(): void
    {
        $response = $this->executeApiWriteRawRequestAs('POST', '/_api/articles', 1, '{invalid json');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('hydra:Error', $body['@type']);
        self::assertSame('Bad Request', $body['hydra:title']);
    }

    public function testPatchWithMalformedJsonReturns400(): void
    {
        $response = $this->executeApiWriteRawRequestAs('PATCH', '/_api/articles/1', 1, '{"title": broken}');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('hydra:Error', $body['@type']);
        self::assertSame('Bad Request', $body['hydra:title']);
    }

    public function testPutWithMalformedJsonReturns400(): void
    {
        $response = $this->executeApiWriteRawRequestAs('PUT', '/_api/articles/1', 1, 'not-json-at-all');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('hydra:Error', $body['@type']);
        self::assertSame('Bad Request', $body['hydra:title']);
    }

    // ── 405 Method Not Allowed ───────────────────────────────────────────────

    public function testUnsupportedMethodReturns405(): void
    {
        $response = $this->executeApiWriteRequest('OPTIONS', '/_api/articles');

        self::assertSame(405, $response->getStatusCode());
    }
}
