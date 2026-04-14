<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Security;

use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for operations config enforcement.
 *
 * RED phase: RequestDispatcher does not check config['general']['operations'].
 * A resource with operations=['list','show'] still dispatches POST/PUT/PATCH/DELETE
 * to the handlers instead of returning 405 Method Not Allowed.
 *
 * Target behaviour:
 *   - Operations not listed in the operations array → 405 hydra:Error
 *   - Allowed operations continue to work normally
 *   - 405 response body is a hydra:Error with description mentioning the operation
 *
 * Fixture resources:
 *   readonly-articles  → operations: [list, show]
 *   createonly-articles → operations: [list, create]
 */
final class OperationsEnforcementTest extends ApiFunctionalTestCase
{
    private const READONLY  = 'readonly-articles';
    private const CREATEONLY = 'createonly-articles';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');

        ApiRegistry::register(self::READONLY, [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => self::READONLY,
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
                'itemsPerPage' => 20,
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        ApiRegistry::register(self::CREATEONLY, [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => self::CREATEONLY,
                'resourceType' => 'Article',
                'operations'   => ['list', 'create'],
                'itemsPerPage' => 20,
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show', 'create', 'update'], 'required' => false],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Read-only resource: allowed operations return correct status ───────────

    public function testReadOnlyAllowsGetCollection(): void
    {
        $response = $this->executeApiRequest('/_api/' . self::READONLY);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testReadOnlyAllowsGetItem(): void
    {
        $response = $this->executeApiRequest('/_api/' . self::READONLY . '/1');

        self::assertSame(200, $response->getStatusCode());
    }

    // ── Read-only resource: disabled write operations → 405 ───────────────────

    public function testReadOnlyReturns405ForPostWithHydraError(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/' . self::READONLY, ['title' => 'Test']);
        $body = $this->decodeResponseBody($response);

        self::assertSame(405, $response->getStatusCode());
        self::assertStringContainsString('application/ld+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('hydra:Error', $body['@type']);
        self::assertStringContainsString('create', $body['hydra:description']);
    }

    public function testReadOnlyReturns405ForPut(): void
    {
        $response = $this->executeApiWriteRequest('PUT', '/_api/' . self::READONLY . '/1', ['title' => 'Test']);

        self::assertSame(405, $response->getStatusCode());
    }

    public function testReadOnlyReturns405ForPatch(): void
    {
        $response = $this->executeApiWriteRequest('PATCH', '/_api/' . self::READONLY . '/1', ['title' => 'Test']);

        self::assertSame(405, $response->getStatusCode());
    }

    public function testReadOnlyReturns405ForDelete(): void
    {
        $response = $this->executeApiWriteRequest('DELETE', '/_api/' . self::READONLY . '/1');

        self::assertSame(405, $response->getStatusCode());
    }

    // ── Create-only resource: allowed operations return correct status ─────────

    public function testCreateOnlyAllowsGetCollection(): void
    {
        $response = $this->executeApiRequest('/_api/' . self::CREATEONLY);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCreateOnlyAllowsPost(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/' . self::CREATEONLY, ['title' => 'New']);

        self::assertSame(201, $response->getStatusCode());
    }

    // ── Create-only resource: disabled operations → 405 ──────────────────────

    public function testCreateOnlyReturns405ForGetItem(): void
    {
        $response = $this->executeApiRequest('/_api/' . self::CREATEONLY . '/1');

        self::assertSame(405, $response->getStatusCode());
    }

    public function testCreateOnlyReturns405ForPatch(): void
    {
        $response = $this->executeApiWriteRequest('PATCH', '/_api/' . self::CREATEONLY . '/1', ['title' => 'Test']);

        self::assertSame(405, $response->getStatusCode());
    }

    public function testCreateOnlyReturns405ForDelete(): void
    {
        $response = $this->executeApiWriteRequest('DELETE', '/_api/' . self::CREATEONLY . '/1');

        self::assertSame(405, $response->getStatusCode());
    }
}
