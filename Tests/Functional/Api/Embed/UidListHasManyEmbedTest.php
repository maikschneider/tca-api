<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for hasMany relation embedding where related UIDs are stored
 * as a comma-separated list in the parent row's own column (no MM table, no foreign_field).
 *
 * The canonical TYPO3 example is fe_users.usergroup → fe_groups.
 *
 * Fixture data (fe_users_with_groups.csv + fe_groups.csv):
 *   fe_user uid=20 → usergroup="1"   (one group: Editors)
 *   fe_user uid=21 → usergroup="2"   (one group: Admins)
 *   fe_user uid=22 → usergroup="1,2" (two groups: Editors, Admins)
 *   fe_user uid=23 → usergroup=""    (no groups)
 *
 *   fe_group uid=1 → Editors
 *   fe_group uid=2 → Admins
 */
final class UidListHasManyEmbedTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users_with_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_groups.csv');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function registerFeGroupResource(): void
    {
        $this->registerResource('uidlist-fe-groups', [
            'general' => [
                'table'        => 'fe_groups',
                'resourceName' => 'uidlist-fe-groups',
                'resourceType' => 'FeGroup',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerFeUserResource(array $columnOverrides = []): void
    {
        $this->registerResource('uidlist-fe-users', [
            'general' => [
                'table'        => 'fe_users',
                'resourceName' => 'uidlist-fe-users',
                'resourceType' => 'FeUser',
                'operations'   => ['list', 'show'],
            ],
            'columns' => array_merge([
                'username'  => ['groups' => ['list', 'show']],
                'usergroup' => ['groups' => ['list', 'show']],
            ], $columnOverrides),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Without embed: stubs ──────────────────────────────────────────────────

    public function testUidListHasManyWithoutEmbedReturnsStubs(): void
    {
        $this->registerFeGroupResource();
        $this->registerFeUserResource();

        $response = $this->executeApiRequest('/_api/uidlist-fe-users/22');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['usergroup']);
        self::assertCount(2, $body['usergroup']);
        self::assertIsString($body['usergroup'][0]);
        self::assertMatchesRegularExpression('#/_api/[^/]+/\d+$#', $body['usergroup'][0]);
    }

    // ── With embed: full records ───────────────────────────────────────────────

    public function testUidListHasManyWithEmbedReturnsTwoGroupsForUid22(): void
    {
        $this->registerFeGroupResource();
        $this->registerFeUserResource([
            'usergroup' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/uidlist-fe-users/22');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['usergroup']);
        self::assertCount(2, $body['usergroup']);

        $titles = array_column($body['usergroup'], 'title');
        self::assertContains('Editors', $titles);
        self::assertContains('Admins', $titles);
    }

    public function testUidListHasManyWithEmbedReturnsOneGroupForUid20(): void
    {
        $this->registerFeGroupResource();
        $this->registerFeUserResource([
            'usergroup' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/uidlist-fe-users/20');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['usergroup']);
        self::assertCount(1, $body['usergroup']);
        self::assertSame('Editors', $body['usergroup'][0]['title']);
    }

    public function testUidListHasManyEmptyReturnsEmptyArray(): void
    {
        $this->registerFeGroupResource();
        $this->registerFeUserResource([
            'usergroup' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        // uid=23 has usergroup=""
        $response = $this->executeApiRequest('/_api/uidlist-fe-users/23');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['usergroup']);
    }

    public function testUidListHasManyEmbeddedItemsHaveJsonLdFields(): void
    {
        $this->registerFeGroupResource();
        $this->registerFeUserResource([
            'usergroup' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/uidlist-fe-users/22');
        $body     = $this->decodeResponseBody($response);

        $group = $body['usergroup'][0];
        self::assertArrayHasKey('@id', $group);
        self::assertArrayHasKey('@type', $group);
        self::assertArrayHasKey('uid', $group);
        self::assertSame('FeGroup', $group['@type']);
        self::assertStringStartsWith('/_api/', $group['@id']);
    }

    // ── Collection endpoint ───────────────────────────────────────────────────

    public function testUidListHasManyCollectionEmbedWorksForAllMembers(): void
    {
        $this->registerFeGroupResource();
        $this->registerFeUserResource([
            'usergroup' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/uidlist-fe-users');
        $body     = $this->decodeResponseBody($response);

        $members = array_column($body['hydra:member'], null, 'uid');

        // uid=20: one group
        self::assertCount(1, $members[20]['usergroup']);
        self::assertSame('Editors', $members[20]['usergroup'][0]['title']);

        // uid=22: two groups
        self::assertCount(2, $members[22]['usergroup']);

        // uid=23: no groups
        self::assertSame([], $members[23]['usergroup']);
    }
}
