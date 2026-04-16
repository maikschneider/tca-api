<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Security;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for declarative AccessRole::OWNER ownership security.
 *
 * Covers:
 *  - OWNER role on update/delete: owner allowed, non-owner denied, unauthenticated denied
 *  - BE_ADMIN bypass (beAdminBypass default true / explicit false)
 *  - OWNER without ownership.column → 403 (fail-secure)
 *  - CreateHandler: server-side fe_user_id injection
 *  - CreateHandler: setOnCreate column injection
 *  - ColumnFilterTrait: ownership columns stripped from client input
 */
final class OwnershipAccessTest extends ApiFunctionalTestCase
{
    /** Resource config for ownership-gated update/delete (article uid=90, fe_user_id=1) */
    private const OWNED_CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'owned-arts',
            'resourceType' => 'Article',
            'operations'   => ['show', 'create', 'update', 'delete'],
            'itemsPerPage' => 20,
        ],
        'columns' => [
            'title' => [
                'groups'   => ['show', 'create', 'update'],
                'required' => true,
            ],
            'fe_user_id' => [
                'groups' => ['show'],
            ],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
        'security' => [
            'show'   => AccessRole::PUBLIC,
            'create' => AccessRole::FE_USER,
            'update' => AccessRole::OWNER,
            'delete' => AccessRole::OWNER,
        ],
        'ownership' => [
            'column' => 'fe_user_id',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_owned.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
    }

    // ── Update: ownership gating ──────────────────────────────────────────────

    public function testOwnerCanUpdate(): void
    {
        ApiRegistry::register('owned-arts', self::OWNED_CONFIG);

        $response = $this->executeApiWriteRequestAs('PUT', '/_api/owned-arts/90', 1, ['title' => 'By Owner']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testNonOwnerCannotUpdate(): void
    {
        ApiRegistry::register('owned-arts', self::OWNED_CONFIG);

        $response = $this->executeApiWriteRequestAs('PUT', '/_api/owned-arts/90', 2, ['title' => 'By Non-Owner']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUnauthenticatedCannotUpdate(): void
    {
        ApiRegistry::register('owned-arts', self::OWNED_CONFIG);

        $response = $this->executeApiWriteRequest('PUT', '/_api/owned-arts/90', ['title' => 'Anon']);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── Delete: ownership gating ──────────────────────────────────────────────

    public function testOwnerCanDelete(): void
    {
        ApiRegistry::register('owned-arts', self::OWNED_CONFIG);

        $response = $this->executeApiWriteRequestAs('DELETE', '/_api/owned-arts/90', 1);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testNonOwnerCannotDelete(): void
    {
        ApiRegistry::register('owned-arts', self::OWNED_CONFIG);

        $response = $this->executeApiWriteRequestAs('DELETE', '/_api/owned-arts/90', 2);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── BE_ADMIN bypass ───────────────────────────────────────────────────────

    public function testBeAdminCanUpdateWithDefaultBypass(): void
    {
        // beAdminBypass defaults to true — BE_ADMIN bypasses ownership check
        ApiRegistry::register('owned-arts', self::OWNED_CONFIG);

        $response = $this->executeApiWriteRequestAsBackendUser('PUT', '/_api/owned-arts/90', 1, ['title' => 'Admin Update']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBeAdminDeniedWhenBypassDisabled(): void
    {
        $config = array_merge(self::OWNED_CONFIG, [
            'ownership' => [
                'column'        => 'fe_user_id',
                'beAdminBypass' => false,
            ],
        ]);
        ApiRegistry::register('owned-arts', $config);

        // BE_ADMIN uid=1 is not the FE owner — bypass disabled → 403
        $response = $this->executeApiWriteRequestAsBackendUser('PUT', '/_api/owned-arts/90', 1, ['title' => 'Admin No Bypass']);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── OWNER without ownership.column → fail-secure 403 ─────────────────────

    public function testOwnerRoleWithoutOwnershipColumnReturnsForbidden(): void
    {
        $config = [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'owned-arts',
                'resourceType' => 'Article',
                'operations'   => ['show', 'update'],
                'itemsPerPage' => 20,
            ],
            'columns' => [
                'title' => ['groups' => ['show', 'update']],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
            'security' => [
                'show'   => AccessRole::PUBLIC,
                'update' => AccessRole::OWNER,
                // No 'ownership' key → isOwner() returns false
            ],
        ];
        ApiRegistry::register('owned-arts', $config);

        $response = $this->executeApiWriteRequestAs('PUT', '/_api/owned-arts/90', 1, ['title' => 'Should Fail']);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── Create: server-side fe_user_id injection ──────────────────────────────

    public function testCreateInjectsFeUserIdServerSide(): void
    {
        ApiRegistry::register('owned-arts', self::OWNED_CONFIG);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/owned-arts', 1, ['title' => 'New Owned']);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $body['fe_user_id']);
    }

    public function testCreateIgnoresClientSuppliedOwnershipColumn(): void
    {
        ApiRegistry::register('owned-arts', self::OWNED_CONFIG);

        // Client tries to claim ownership as user 99 — server must overwrite with logged-in user (1)
        $response = $this->executeApiWriteRequestAs('POST', '/_api/owned-arts', 1, [
            'title'      => 'Poison Attempt',
            'fe_user_id' => 99,
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $body['fe_user_id']);
    }

    // ── Create: setOnCreate separate column ───────────────────────────────────

    public function testCreateUsesSetOnCreateColumnWhenConfigured(): void
    {
        // setOnCreate → 'first_name'; column → 'fe_user_id' (auth only, not injected on create)
        $config = array_merge(self::OWNED_CONFIG, [
            'columns' => array_merge(self::OWNED_CONFIG['columns'], [
                'first_name' => ['groups' => ['show']],
            ]),
            'ownership' => [
                'column'      => 'fe_user_id',
                'setOnCreate' => 'first_name',
            ],
        ]);
        ApiRegistry::register('owned-arts', $config);

        $response = $this->executeApiWriteRequestAs('POST', '/_api/owned-arts', 1, ['title' => 'Tracked']);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        // first_name receives the FE user UID
        self::assertSame('1', (string)$body['first_name']);
        // fe_user_id is NOT auto-injected on create (only setOnCreate column is)
        self::assertSame(0, $body['fe_user_id']);
    }

    // ── Create: no injection when unauthenticated ─────────────────────────────

    public function testCreateSkipsOwnerInjectionWhenUnauthenticated(): void
    {
        // security.create = PUBLIC so unauthenticated create is allowed
        $config = array_merge(self::OWNED_CONFIG, [
            'security' => array_merge(self::OWNED_CONFIG['security'], [
                'create' => AccessRole::PUBLIC,
            ]),
        ]);
        ApiRegistry::register('owned-arts', $config);

        $response = $this->executeApiWriteRequest('POST', '/_api/owned-arts', ['title' => 'Anon Created']);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        // No FE user → fe_user_id should remain 0 (default, not injected)
        self::assertSame(0, $body['fe_user_id']);
    }
}
