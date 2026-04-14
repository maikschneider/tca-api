<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Security;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestOwnerChecker;

/**
 * Functional tests for object-level (record-level) access control on write operations.
 *
 * The callable voter for update/delete receives both the request and the existing
 * DB record so it can compare ownership fields (e.g. fe_user_id === current user).
 *
 * Fixture data:
 *   Article 90 → fe_user_id = 1  (owned by FE user 1)
 */
final class ObjectLevelSecurityTest extends ApiFunctionalTestCase
{
    private const BASE_CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'owned-articles',
            'resourceType' => 'Article',
            'operations'   => ['show', 'update', 'delete'],
            'itemsPerPage' => 20,
        ],
        'columns' => [
            'title' => [
                'groups' => ['list', 'show', 'create', 'update'],
            ],
            'fe_user_id' => [
                'groups' => ['list', 'show'],
            ],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
        'security' => [
            'show'   => AccessRole::PUBLIC,
            'update' => [TestOwnerChecker::class, 'isOwner'],
            'delete' => [TestOwnerChecker::class, 'isOwner'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_owned.csv');
    }

    // ── PATCH: owner (fe_user 1) is allowed ──────────────────────────────────

    public function testOwnerCanUpdateOwnRecord(): void
    {
        ApiRegistry::register('owned-articles', self::BASE_CONFIG);

        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/owned-articles/90', 1, [
            'title' => 'Updated by Owner',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    // ── PATCH: non-owner (fe_user 2) is denied ───────────────────────────────

    public function testNonOwnerCannotUpdateRecord(): void
    {
        ApiRegistry::register('owned-articles', self::BASE_CONFIG);

        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/owned-articles/90', 2, [
            'title' => 'Updated by Non-Owner',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── DELETE: owner is allowed ──────────────────────────────────────────────

    public function testOwnerCanDeleteOwnRecord(): void
    {
        ApiRegistry::register('owned-articles', self::BASE_CONFIG);

        $response = $this->executeApiWriteRequestAs('DELETE', '/_api/owned-articles/90', 1, []);

        self::assertSame(204, $response->getStatusCode());
    }

    // ── DELETE: non-owner is denied ───────────────────────────────────────────

    public function testNonOwnerCannotDeleteRecord(): void
    {
        ApiRegistry::register('owned-articles', self::BASE_CONFIG);

        $response = $this->executeApiWriteRequestAs('DELETE', '/_api/owned-articles/90', 2, []);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── No auth: unauthenticated request is denied ────────────────────────────

    public function testUnauthenticatedUserCannotUpdate(): void
    {
        ApiRegistry::register('owned-articles', self::BASE_CONFIG);

        $response = $this->executeApiWriteRequest('PATCH', '/_api/owned-articles/90', [
            'title' => 'Unauthenticated',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── Non-existent record returns 404 before voter runs ────────────────────

    public function testUpdateNonExistentRecordReturns404(): void
    {
        ApiRegistry::register('owned-articles', self::BASE_CONFIG);

        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/owned-articles/999', 1, [
            'title' => 'Ghost',
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    // ── Existing callable tests remain backward compatible ────────────────────

    public function testCallableWithoutRecordParamStillWorks(): void
    {
        ApiRegistry::register('owned-articles', array_merge(self::BASE_CONFIG, [
            'security' => [
                'show'   => AccessRole::PUBLIC,
                'update' => AccessRole::PUBLIC,
                'delete' => AccessRole::PUBLIC,
            ],
        ]));

        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/owned-articles/90', 1, [
            'title' => 'Public Update',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }
}
