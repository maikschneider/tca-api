<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestCallableChecker;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;

/**
 * Functional tests for Tier-2 PHP callable access control.
 *
 * The security config accepts [ClassName::class, 'method'] in addition to
 * AccessRole enums. The callable receives the ServerRequestInterface and
 * returns bool. This enables record-level, attribute-based, and custom
 * access logic beyond the four built-in roles.
 */
final class CallableAccessTest extends ApiFunctionalTestCase
{
    private const BASE_CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'callable-articles',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show', 'create', 'update', 'delete'],
            'itemsPerPage' => 20,
        ],
        'columns' => [
            'title' => [
                'type'     => 'string',
                'readable' => true,
                'writable' => true,
                'required' => false,
            ],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/fe_users.csv');
    }

    // ── Callable returning true grants access regardless of auth state ────────

    public function testCallableThatReturnsTrueGrantsAccessWithoutAuth(): void
    {
        ApiRegistry::register('callable-articles', array_merge(self::BASE_CONFIG, [
            'security' => [
                'update' => [TestCallableChecker::class, 'allowAll'],
            ],
        ]));

        $response = $this->executeApiWriteRequest('PUT', '/_api/callable-articles/1', ['title' => 'Test']);

        self::assertSame(200, $response->getStatusCode());
    }

    // ── Callable returning false denies access even with a valid FE user ──────

    public function testCallableThatReturnsFalseDeniesAccessWithFeUser(): void
    {
        ApiRegistry::register('callable-articles', array_merge(self::BASE_CONFIG, [
            'security' => [
                'update' => [TestCallableChecker::class, 'denyAll'],
            ],
        ]));

        $response = $this->executeApiWriteRequestAs('PUT', '/_api/callable-articles/1', 1, ['title' => 'Test']);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── Callable receives the request and can use it for custom logic ─────────

    public function testCallableGrantsAccessWhenCustomHeaderPresent(): void
    {
        ApiRegistry::register('callable-articles', array_merge(self::BASE_CONFIG, [
            'security' => [
                'update' => [TestCallableChecker::class, 'checkHeader'],
            ],
        ]));

        $response = $this->executeFrontendSubRequest(
            (new InternalRequest('http://localhost/_api/callable-articles/1'))
                ->withMethod('PUT')
                ->withAddedHeader('Content-Type', 'application/json')
                ->withAddedHeader('X-Test-Allow', '1'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCallableDeniesAccessWhenCustomHeaderAbsent(): void
    {
        ApiRegistry::register('callable-articles', array_merge(self::BASE_CONFIG, [
            'security' => [
                'update' => [TestCallableChecker::class, 'checkHeader'],
            ],
        ]));

        $response = $this->executeApiWriteRequest('PUT', '/_api/callable-articles/1', ['title' => 'Test']);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── Callable on GET (show) operation ──────────────────────────────────────

    public function testCallableCanProtectReadOperation(): void
    {
        ApiRegistry::register('callable-articles', array_merge(self::BASE_CONFIG, [
            'security' => [
                'show' => [TestCallableChecker::class, 'denyAll'],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/callable-articles/1');

        self::assertSame(403, $response->getStatusCode());
    }

    // ── Callable with FE user context: has access to request attributes ───────

    public function testCallableCanInspectFrontendUserViaRequest(): void
    {
        ApiRegistry::register('callable-articles', array_merge(self::BASE_CONFIG, [
            'security' => [
                'show' => [TestCallableChecker::class, 'requireFeUser'],
            ],
        ]));

        // Without FE user: denied
        $deniedResponse = $this->executeApiRequest('/_api/callable-articles/1');
        self::assertSame(403, $deniedResponse->getStatusCode());

        // With FE user uid=1: allowed
        $allowedResponse = $this->executeFrontendSubRequest(
            new InternalRequest('http://localhost/_api/callable-articles/1'),
            (new InternalRequestContext())->withFrontendUserId(1),
        );
        self::assertSame(200, $allowedResponse->getStatusCode());
    }

    // ── Operations without a callable entry fall back to PUBLIC ───────────────

    public function testOperationWithoutSecurityEntryIsPublic(): void
    {
        ApiRegistry::register('callable-articles', array_merge(self::BASE_CONFIG, [
            'security' => [
                // only update has an entry — list/show are absent → default PUBLIC
                'update' => [TestCallableChecker::class, 'denyAll'],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/callable-articles');

        self::assertSame(200, $response->getStatusCode());
    }

    // ── 403 response carries the correct Content-Type ─────────────────────────

    public function testCallable403ResponseHasJsonLdContentType(): void
    {
        ApiRegistry::register('callable-articles', array_merge(self::BASE_CONFIG, [
            'security' => [
                'show' => [TestCallableChecker::class, 'denyAll'],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/callable-articles/1');

        self::assertStringContainsString('application/ld+json', $response->getHeaderLine('Content-Type'));
    }
}
