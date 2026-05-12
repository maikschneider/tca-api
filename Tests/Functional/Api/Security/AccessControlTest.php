<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Security;

use MaikSchneider\TcaApi\Enum\AccessRole;
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
    private const BE_USER_CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'be-articles',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show', 'create', 'update', 'delete'],
            'storagePid' => 1,
        ],
        'columns' => [
            'title' => [
                'type'   => 'string',
                'groups' => ['list', 'show', 'create', 'update'],
            ],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
    ];

    private const FE_GROUP_CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'group-articles',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show', 'create', 'update', 'delete'],
            'storagePid' => 1,
        ],
        'columns' => [
            'title' => [
                'type'   => 'string',
                'groups' => ['list', 'show', 'create', 'update'],
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
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

    public function testCreateWithoutAuthReturns403WithHydraError(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/articles', ['title' => 'Unauthorized']);
        $body = $this->decodeResponseBody($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('application/ld+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('hydra:Error', $body['@type']);
        self::assertSame('Access Denied', $body['hydra:title']);
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
        $response = $this->executeApiWriteRequestAsBackendUser('DELETE', '/_api/articles/1', 2);

        self::assertSame(204, $response->getStatusCode());
    }

    // ── BE_ADMIN denies non-admin backend user ────────────────────────────────

    public function testDeleteReturns403WithNonAdminBackendUser(): void
    {
        $response = $this->executeApiWriteRequestAsBackendUser('DELETE', '/_api/articles/1', 3);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── BE_USER — requires any authenticated backend user ─────────────────────

    public function testBeUserRoleDeniesWithoutAuth(): void
    {
        $this->registerResource('be-articles', array_merge(self::BE_USER_CONFIG, [
            'security' => [
                'show'   => AccessRole::BE_USER,
                'create' => AccessRole::BE_USER,
                'update' => AccessRole::BE_USER,
                'delete' => AccessRole::BE_USER,
            ],
        ]));

        $response = $this->executeApiRequest('/_api/be-articles/1');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testBeUserRoleDeniesWithFeUserOnly(): void
    {
        $this->registerResource('be-articles', array_merge(self::BE_USER_CONFIG, [
            'security' => [
                'show' => AccessRole::BE_USER,
            ],
        ]));

        $response = $this->executeApiRequestAs('/_api/be-articles/1', 1);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testBeUserRoleGrantsAccessToNonAdminBackendUser(): void
    {
        $this->registerResource('be-articles', array_merge(self::BE_USER_CONFIG, [
            'security' => [
                'show' => AccessRole::BE_USER,
            ],
        ]));

        $response = $this->executeApiRequestAsBackendUser('/_api/be-articles/1', 3);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBeUserRoleGrantsAccessToAdminBackendUser(): void
    {
        $this->registerResource('be-articles', array_merge(self::BE_USER_CONFIG, [
            'security' => [
                'show' => AccessRole::BE_USER,
            ],
        ]));

        $response = $this->executeApiRequestAsBackendUser('/_api/be-articles/1', 2);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBeUserRoleCreateDeniedWithoutAuth(): void
    {
        $this->registerResource('be-articles', array_merge(self::BE_USER_CONFIG, [
            'security' => [
                'create' => AccessRole::BE_USER,
            ],
        ]));

        $response = $this->executeApiWriteRequest('POST', '/_api/be-articles', ['title' => 'Unauthorized']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testBeUserRoleCreateSucceedsWithBackendUser(): void
    {
        $this->registerResource('be-articles', array_merge(self::BE_USER_CONFIG, [
            'security' => [
                'create' => AccessRole::BE_USER,
            ],
        ]));

        $response = $this->executeApiWriteRequestAsBackendUser('POST', '/_api/be-articles', 2, ['title' => 'BE Created']);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testBeUserRoleUpdateSucceedsWithBackendUser(): void
    {
        $this->registerResource('be-articles', array_merge(self::BE_USER_CONFIG, [
            'security' => [
                'update' => AccessRole::BE_USER,
            ],
        ]));

        $response = $this->executeApiWriteRequestAsBackendUser('PUT', '/_api/be-articles/1', 3, ['title' => 'BE Updated']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBeUserRoleDeleteSucceedsWithBackendUser(): void
    {
        $this->registerResource('be-articles', array_merge(self::BE_USER_CONFIG, [
            'security' => [
                'delete' => AccessRole::BE_USER,
            ],
        ]));

        $response = $this->executeApiWriteRequestAsBackendUser('DELETE', '/_api/be-articles/1', 3);

        self::assertSame(204, $response->getStatusCode());
    }

    // ── FE_GROUP — requires FE user with at least one group ───────────────────

    public function testFeGroupRoleDeniesWithoutAuth(): void
    {
        $this->registerResource('group-articles', array_merge(self::FE_GROUP_CONFIG, [
            'security' => [
                'show' => AccessRole::FE_GROUP,
            ],
        ]));

        $response = $this->executeApiRequest('/_api/group-articles/1');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testFeGroupRoleDeniesFeUserWithoutGroup(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users_with_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_groups.csv');

        $this->registerResource('group-articles', array_merge(self::FE_GROUP_CONFIG, [
            'security' => [
                'show' => AccessRole::FE_GROUP,
            ],
        ]));

        // fe_user uid=23 has empty usergroup
        $response = $this->executeApiRequestAs('/_api/group-articles/1', 23);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testFeGroupRoleGrantsFeUserWithGroup(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users_with_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_groups.csv');

        $this->registerResource('group-articles', array_merge(self::FE_GROUP_CONFIG, [
            'security' => [
                'show' => AccessRole::FE_GROUP,
            ],
        ]));

        // fe_user uid=20 has usergroup=1
        $response = $this->executeApiRequestAs('/_api/group-articles/1', 20);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testFeGroupRoleGrantsFeUserWithMultipleGroups(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users_with_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_groups.csv');

        $this->registerResource('group-articles', array_merge(self::FE_GROUP_CONFIG, [
            'security' => [
                'show' => AccessRole::FE_GROUP,
            ],
        ]));

        // fe_user uid=22 has usergroup=1,2
        $response = $this->executeApiRequestAs('/_api/group-articles/1', 22);

        self::assertSame(200, $response->getStatusCode());
    }

    // ── FE_GROUP with specific group IDs (array syntax) ───────────────────────

    public function testFeGroupWithSpecificIdsGrantsMatchingGroup(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users_with_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_groups.csv');

        $this->registerResource('group-articles', array_merge(self::FE_GROUP_CONFIG, [
            'security' => [
                'show' => [AccessRole::FE_GROUP, [1]],
            ],
        ]));

        // fe_user uid=20 has usergroup=1
        $response = $this->executeApiRequestAs('/_api/group-articles/1', 20);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testFeGroupWithSpecificIdsDeniesNonMatchingGroup(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users_with_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_groups.csv');

        $this->registerResource('group-articles', array_merge(self::FE_GROUP_CONFIG, [
            'security' => [
                'show' => [AccessRole::FE_GROUP, [3]],
            ],
        ]));

        // fe_user uid=20 has usergroup=1 — group 3 not matched
        $response = $this->executeApiRequestAs('/_api/group-articles/1', 20);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testFeGroupWithSpecificIdsDeniesWithoutAuth(): void
    {
        $this->registerResource('group-articles', array_merge(self::FE_GROUP_CONFIG, [
            'security' => [
                'show' => [AccessRole::FE_GROUP, [1]],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/group-articles/1');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testFeGroupWriteOperationDeniesWithoutAuth(): void
    {
        $this->registerResource('group-articles', array_merge(self::FE_GROUP_CONFIG, [
            'security' => [
                'create' => AccessRole::FE_GROUP,
            ],
        ]));

        $response = $this->executeApiWriteRequest('POST', '/_api/group-articles', ['title' => 'Unauthorized']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testFeGroupWriteOperationGrantsFeUserWithGroup(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users_with_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_groups.csv');

        $this->registerResource('group-articles', array_merge(self::FE_GROUP_CONFIG, [
            'security' => [
                'create' => AccessRole::FE_GROUP,
            ],
        ]));

        // fe_user uid=20 has usergroup=1
        $response = $this->executeApiWriteRequestAs('POST', '/_api/group-articles', 20, ['title' => 'Group Created']);

        self::assertSame(201, $response->getStatusCode());
    }
}
