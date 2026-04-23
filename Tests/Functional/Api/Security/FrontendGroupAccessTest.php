<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Security;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;

/**
 * Functional tests for FE_GROUP access role.
 *
 * Target config format:
 *   [AccessRole::FE_GROUP, [1, 2]]  — user must be member of group 1 OR 2
 *   AccessRole::FE_GROUP            — user must have any group membership
 *
 * Fixture users (fe_users_with_groups.csv):
 *   uid=20 → usergroup='1'   (Editors only)
 *   uid=21 → usergroup='2'   (Admins only)
 *   uid=22 → usergroup='1,2' (Editors + Admins)
 *   uid=23 → usergroup=''    (no groups)
 *
 * Fixture groups (fe_groups.csv):
 *   uid=1 → Editors
 *   uid=2 → Admins
 *   uid=3 → Other
 */
final class FrontendGroupAccessTest extends ApiFunctionalTestCase
{
    private const RESOURCE = 'group-articles';

    private const BASE_CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => self::RESOURCE,
            'resourceType' => 'Article',
            'operations'   => ['list', 'show', 'create'],
        ],
        'columns' => [
            'title' => ['groups' => ['list', 'show', 'create', 'update']],
        ],
        'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users_with_groups.csv');
    }

    // ── Specific group restriction ────────────────────────────────────────────

    public function testUserInRequiredGroupGetsAccess(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'security' => ['show' => [AccessRole::FE_GROUP, [1]]],
        ]));

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('http://localhost/_api/' . self::RESOURCE . '/1'),
            (new InternalRequestContext())->withFrontendUserId(20), // uid=20 is in group 1
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testUserNotInRequiredGroupIsDenied(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'security' => ['show' => [AccessRole::FE_GROUP, [1]]],
        ]));

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('http://localhost/_api/' . self::RESOURCE . '/1'),
            (new InternalRequestContext())->withFrontendUserId(21), // uid=21 is in group 2 only
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUnauthenticatedUserIsDeniedByGroupRestriction(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'security' => ['show' => [AccessRole::FE_GROUP, [1]]],
        ]));

        $response = $this->executeApiRequest('/_api/' . self::RESOURCE . '/1');

        self::assertSame(403, $response->getStatusCode());
    }

    // ── Multiple allowed groups (OR logic) ────────────────────────────────────

    public function testUserInAnyOfMultipleAllowedGroupsGetsAccess(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'security' => ['show' => [AccessRole::FE_GROUP, [1, 2]]],
        ]));

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('http://localhost/_api/' . self::RESOURCE . '/1'),
            (new InternalRequestContext())->withFrontendUserId(21), // uid=21 is in group 2
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testUserInNonListedGroupIsDenied(): void
    {
        // uid=20 is in group 1 only; required group is 3
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'security' => ['show' => [AccessRole::FE_GROUP, [3]]],
        ]));

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('http://localhost/_api/' . self::RESOURCE . '/1'),
            (new InternalRequestContext())->withFrontendUserId(20),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUserInMultipleGroupsMatchesOneOfAllowed(): void
    {
        // uid=22 is in groups 1+2; config requires group 2 only
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'security' => ['show' => [AccessRole::FE_GROUP, [2]]],
        ]));

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('http://localhost/_api/' . self::RESOURCE . '/1'),
            (new InternalRequestContext())->withFrontendUserId(22),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    // ── Bare FE_GROUP: any group membership required ──────────────────────────

    public function testBareFeGroupAcceptsUserWithAnyGroup(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'security' => ['show' => AccessRole::FE_GROUP],
        ]));

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('http://localhost/_api/' . self::RESOURCE . '/1'),
            (new InternalRequestContext())->withFrontendUserId(20), // in group 1
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBareFeGroupDeniesUserWithNoGroups(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'security' => ['show' => AccessRole::FE_GROUP],
        ]));

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('http://localhost/_api/' . self::RESOURCE . '/1'),
            (new InternalRequestContext())->withFrontendUserId(23), // no groups
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testBareFeGroupDeniesUnauthenticatedUser(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'security' => ['show' => AccessRole::FE_GROUP],
        ]));

        $response = $this->executeApiRequest('/_api/' . self::RESOURCE . '/1');

        self::assertSame(403, $response->getStatusCode());
    }

    // ── 403 response format ───────────────────────────────────────────────────

    public function test403ResponseFormatIsHydraError(): void
    {
        $this->registerResource(self::RESOURCE, array_merge(self::BASE_CONFIG, [
            'security' => ['show' => [AccessRole::FE_GROUP, [1]]],
        ]));

        $response = $this->executeFrontendSubRequest(
            new InternalRequest('http://localhost/_api/' . self::RESOURCE . '/1'),
            (new InternalRequestContext())->withFrontendUserId(21), // group 2, not group 1
        );

        $body = $this->decodeResponseBody($response);
        self::assertStringContainsString('application/ld+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('hydra:Error', $body['@type']);
    }
}
