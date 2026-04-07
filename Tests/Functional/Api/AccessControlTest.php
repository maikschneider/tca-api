<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for access control enforcement via the security config.
 *
 * Articles config:
 *   list   → PUBLIC    (no auth needed)
 *   show   → PUBLIC    (no auth needed)
 *   create → FE_USER   (frontend user required)
 *   update → FE_USER   (frontend user required)
 *   delete → BE_ADMIN  (backend admin required)
 */
final class AccessControlTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
    }

    // ── PUBLIC endpoints — accessible without auth ────────────────────────────

    public function testListIsAccessibleWithoutAuth(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testShowIsAccessibleWithoutAuth(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');

        self::assertSame(200, $response->getStatusCode());
    }

    // ── Create requires FE_USER ───────────────────────────────────────────────

    public function testCreateReturns403WithoutAuth(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/articles', ['title' => 'Unauthorized']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCreateReturns403ResponseHasJsonLdContentType(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/articles', ['title' => 'Unauthorized']);

        self::assertStringContainsString('application/ld+json', $response->getHeaderLine('Content-Type'));
    }

    public function testCreateSucceedsWithFeUser(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, ['title' => 'Auth Article']);

        self::assertSame(201, $response->getStatusCode());
    }

    // ── Update (PUT) requires FE_USER ─────────────────────────────────────────

    public function testPutReturns403WithoutAuth(): void
    {
        $response = $this->executeApiWriteRequest('PUT', '/_api/articles/1', ['title' => 'Unauthorized']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPutSucceedsWithFeUser(): void
    {
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, ['title' => 'Auth Update']);

        self::assertSame(200, $response->getStatusCode());
    }

    // ── Update (PATCH) requires FE_USER ──────────────────────────────────────

    public function testPatchReturns403WithoutAuth(): void
    {
        $response = $this->executeApiWriteRequest('PATCH', '/_api/articles/1', ['title' => 'Unauthorized']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPatchSucceedsWithFeUser(): void
    {
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/articles/1', 1, ['title' => 'Auth Patch']);

        self::assertSame(200, $response->getStatusCode());
    }

    // ── Delete requires BE_ADMIN ──────────────────────────────────────────────

    public function testDeleteReturns403WithoutAuth(): void
    {
        $response = $this->executeApiWriteRequest('DELETE', '/_api/articles/1');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDeleteReturns403WithFeUserOnly(): void
    {
        $response = $this->executeApiWriteRequestAs('DELETE', '/_api/articles/1', 1);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDeleteSucceedsWithBeAdmin(): void
    {
        $response = $this->executeApiWriteRequestAsBackendAdmin('DELETE', '/_api/articles/1', 1);

        self::assertSame(204, $response->getStatusCode());
    }

    // ── 403 response body structure ───────────────────────────────────────────

    public function testForbiddenResponseBodyIsHydraError(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/articles', ['title' => 'Test']);
        $body = $this->decodeResponseBody($response);

        self::assertSame('hydra:Error', $body['@type']);
    }

    public function testForbiddenBodyHasAccessDeniedTitle(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/articles', ['title' => 'Test']);
        $body = $this->decodeResponseBody($response);

        self::assertSame('Access Denied', $body['hydra:title']);
    }

    public function testForbiddenBodyDescriptionMentionsOperation(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/articles', ['title' => 'Test']);
        $body = $this->decodeResponseBody($response);

        self::assertStringContainsString('create', $body['hydra:description']);
    }
}
