<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for writing relation fields via POST / PUT / PATCH.
 * color_id and categories now have groups including 'create'/'update' in Articles.php config.
 * Fixture baseline:
 *   Article 1 → color_id=1 (Red), categories=[1 (PHP), 2 (TYPO3)]
 *   Article 2 → color_id=2 (Blue), categories=[3 (API)]
 *   Article 3 → color_id=0 (none), categories=[]
 *   Colors: 1=Red, 2=Blue
 *   SysCategories: 1=PHP, 2=TYPO3, 3=API
 */
final class WriteRelationsTest extends ApiFunctionalTestCase
{
    private const BASE_COLOR_CONFIG = [
        'general' => [
            'table' => 'tx_myext_domain_model_color',
            'resourceName' => 'relation-write-colors',
            'resourceType' => 'Color',
            'operations' => ['list', 'show'],
            'storagePid' => 1,
        ],
        'security' => [
            'create' => AccessRole::FE_USER,
        ],
    ];

    private const BASE_CATEGORY_CONFIG = [
        'general' => [
            'table' => 'sys_category',
            'resourceName' => 'relation-write-categories',
            'resourceType' => 'SysCategory',
            'operations' => ['list', 'show'],
            'itemsPerPage' => 20,
        ],
        'columns' => [
            'title' => [
                'groups' => ['list', 'show'],
            ],
        ],
        'security' => [
            'create' => AccessRole::FE_USER,
            'update' => AccessRole::FE_USER,
        ],
    ];

    private const BASE_ARTICLE_CONFIG = [
        'general' => [
            'table' => 'tx_myext_domain_model_article',
            'resourceName' => 'relation-write-articles',
            'resourceType' => 'Article',
            'operations' => ['list', 'show', 'create', 'update'],
            'storagePid' => 1,
        ],
        'columns' => [
            'title' => ['groups' => ['list', 'show', 'create', 'update'], 'required' => true],
            'color_id' => ['groups' => ['list', 'show', 'create', 'update'], 'resourceName' => 'relation-write-colors'],
            'categories' => ['groups' => ['list', 'show', 'create'], 'resourceName' => 'relation-write-categories'],
        ],
        'security' => [
            'create' => AccessRole::FE_USER,
            'update' => AccessRole::FE_USER,
        ],
        'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
    ];

    // ── hasOne (color_id) ────────────────────────────────────────────────────

    public function testPostWithColorIdSetsColor(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/relation-write-articles', 1, [
            'title' => 'Colored Article',
            'color_id' => 1,
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('color_id', $body);
        self::assertSame('/_api/relation-write-colors/1', $body['color_id']);
    }

    public function testPostWithColorIdPersistedInDatabase(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/relation-write-articles', 1, [
            'title' => 'Persisted Color',
            'color_id' => 2,
        ]);
        $uid = $this->decodeResponseBody($response)['uid'];

        $getResponse = $this->executeApiRequest('/_api/relation-write-articles/' . $uid);
        $body = $this->decodeResponseBody($getResponse);

        self::assertSame('/_api/relation-write-colors/2', $body['color_id']);
    }

    public function testPutUpdatesColorId(): void
    {
        // Article 1 has color_id=1 (Red) → update to color_id=2 (Blue)
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/relation-write-articles/1', 1, [
            'title' => 'First Article',
            'color_id' => 2,
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame('/_api/relation-write-colors/2', $body['color_id']);
    }

    public function testPatchWithZeroColorIdRemovesColor(): void
    {
        // Article 1 has color_id=1 → patch to color_id=0 (remove)
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/relation-write-articles/1', 1, [
            'color_id' => 0,
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('color_id', $body);
        self::assertNull($body['color_id']);
    }

    // ── manyToMany (sys_category) ────────────────────────────────────────────

    public function testPostWithCategoriesSetsCategoryRelations(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/relation-write-articles', 1, [
            'title' => 'Categorized Article',
            'categories' => [1, 2],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('categories', $body);
        self::assertCount(2, $body['categories']);
    }

    public function testPostWithCategoriesResponseContainsCategoryIris(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/relation-write-articles', 1, [
            'title' => 'PHP Article',
            'categories' => [1],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertIsString($body['categories'][0]);
        self::assertStringEndsWith('/1', $body['categories'][0]);
    }

    public function testPutWithCategoriesReplacesCategoryRelations(): void
    {
        // Article 1 has categories [1, 2] → PUT replaces with [3]
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/relation-write-articles/1', 1, [
            'title' => 'First Article',
            'categories' => [3],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertCount(1, $body['categories']);
        self::assertStringEndsWith('/3', $body['categories'][0]);
    }

    public function testPatchWithEmptyCategoriesRemovesAllCategories(): void
    {
        // Article 1 has categories [1, 2] → patch with [] removes them
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/relation-write-articles/1', 1, [
            'categories' => [],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('categories', $body);
        self::assertSame([], $body['categories']);
    }

    public function testPatchCategoriesPersistedInDatabase(): void
    {
        $this->executeApiWriteRequestAs('PATCH', '/_api/relation-write-articles/3', 1, [
            'categories' => [2],
        ]);

        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/relation-write-articles/3'));

        self::assertCount(1, $body['categories']);
        self::assertStringEndsWith('/2', $body['categories'][0]);
    }

    // ── Inline object creation (hasOne) ──────────────────────────────────────

    public function testPostWithNewColorObjectCreatesColorAndLinks(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/relation-write-articles', 1, [
            'title' => 'Fresh Color Article',
            'color_id' => ['name' => 'Fresh'],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertArrayHasKey('color_id', $body);
        self::assertIsString($body['color_id']);
        self::assertStringStartsWith('/_api/relation-write-colors/', $body['color_id']);
        self::assertGreaterThan(2, (int)basename($body['color_id']), 'New color UID should be > 2 (fixtures have 1,2)');
    }

    public function testPostWithNewColorObjectColorPersistedInDatabase(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/relation-write-articles', 1, [
            'title' => 'Persisted New Color',
            'color_id' => ['name' => 'PersistMe'],
        ]);
        $articleUid = $this->decodeResponseBody($response)['uid'];

        $getBody = $this->decodeResponseBody($this->executeApiRequest('/_api/relation-write-articles/' . $articleUid));

        self::assertIsString($getBody['color_id']);
        self::assertStringStartsWith('/_api/relation-write-colors/', $getBody['color_id']);
        self::assertGreaterThan(2, (int)basename($getBody['color_id']));
    }

    public function testPutWithNewColorObjectReplacesRelation(): void
    {
        // Article 1 currently has color_id=1 (Red)
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/relation-write-articles/1', 1, [
            'title' => 'First Article',
            'color_id' => ['name' => 'Replaced'],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($body['color_id']);
        self::assertStringStartsWith('/_api/relation-write-colors/', $body['color_id']);
        self::assertGreaterThan(2, (int)basename($body['color_id']), 'New color should have uid > 2');
    }

    // ── Inline object creation (hasMany / MM) ─────────────────────────────────

    public function testPostWithNewCategoryObjectCreatesCategoryAndLinks(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/relation-write-articles', 1, [
            'title' => 'New Cat Article',
            'categories' => [['title' => 'Inline Category']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $body['categories']);
        self::assertIsString($body['categories'][0]);
        self::assertGreaterThan(3, (int)basename($body['categories'][0]), 'New cat UID should be > 3 (fixtures have 1-3)');
    }

    public function testPostWithMixedCategoriesMixesNewAndExisting(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/relation-write-articles', 1, [
            'title' => 'Mixed Cat Article',
            'categories' => [1, ['title' => 'Mixed New']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(2, $body['categories']);

        $uids = array_map(fn (string $iri) => (int)basename($iri), $body['categories']);
        self::assertContains(1, $uids, 'Existing category uid=1 should be linked');
        foreach ($uids as $uid) {
            if ($uid !== 1) {
                self::assertGreaterThan(3, $uid, 'New category UID should be > 3');
            }
        }
    }

    public function testPatchWithMixedCategoriesLinksNewAndExisting(): void
    {
        // Article 3 has no categories → patch with 1 existing + 1 new
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/relation-write-articles/3', 1, [
            'categories' => [2, ['title' => 'Patch New Cat']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['categories']);

        $uids = array_map(fn (string $iri) => (int)basename($iri), $body['categories']);
        self::assertContains(2, $uids);
    }

    // ── Sub-entity ownership injection (prepareChildData) ─────────────────────

    public function testPostWithNewCategoryObjectCategoryPersistedInDatabase(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/relation-write-articles', 1, [
            'title' => 'Persist Cat Article',
            'categories' => [['title' => 'PersistCat']],
        ]);
        $articleUid = $this->decodeResponseBody($response)['uid'];

        $getBody = $this->decodeResponseBody($this->executeApiRequest('/_api/relation-write-articles/' . $articleUid));

        self::assertCount(1, $getBody['categories']);
        self::assertIsString($getBody['categories'][0]);
        self::assertGreaterThan(3, (int)basename($getBody['categories'][0]));
    }

    public function testPutWithNewCategoryObjectReplacesCategoryRelations(): void
    {
        // Article 1 has categories [1, 2] → PUT replaces with 1 new category
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/relation-write-articles/1', 1, [
            'title' => 'First Article',
            'categories' => [['title' => 'PutNewCat']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['categories']);
        self::assertIsString($body['categories'][0]);
        self::assertGreaterThan(3, (int)basename($body['categories'][0]), 'New category UID should be > 3');
    }

    public function testPostNewSubEntityGetsOwnerColumnInjected(): void
    {
        // Register Colors with ownership.column = 'hex' so FE user UID is injected there
        $this->registerResource('relation-write-colors', array_merge(self::BASE_COLOR_CONFIG, ['ownership' => ['column' => 'hex']]));

        $response = $this->executeApiWriteRequestAs('POST', '/_api/relation-write-articles', 1, [
            'title' => 'Owner Article',
            'color_id' => ['name' => 'OwnedColor'],
        ]);
        $colorUid = (int)basename($this->decodeResponseBody($response)['color_id']);

        $colorRow = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['hex'], 'tx_myext_domain_model_color', ['uid' => $colorUid])
            ->fetchAssociative() ?: [];

        // FE user uid=1 must be injected into the ownership column
        self::assertSame('1', (string)$colorRow['hex']);
    }

    public function testPostNewSubEntityClientOwnershipValueIsStripped(): void
    {
        // Client attempts to set hex='hacker' — server must overwrite with FE user UID
        $this->registerResource('relation-write-colors', array_merge(self::BASE_COLOR_CONFIG, ['ownership' => ['column' => 'hex']]));

        $response = $this->executeApiWriteRequestAs('POST', '/_api/relation-write-articles', 1, [
            'title' => 'Strip Client Value',
            'color_id' => ['name' => 'Color', 'hex' => 'hacker'],
        ]);
        $colorUid = (int)basename($this->decodeResponseBody($response)['color_id']);

        $colorRow = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['hex'], 'tx_myext_domain_model_color', ['uid' => $colorUid])
            ->fetchAssociative() ?: [];

        self::assertSame('1', (string)$colorRow['hex'], 'Client hex value must be stripped and replaced by server-injected FE user UID');
    }

    public function testPostNewSubEntityGetsSetOnCreateColumnInjected(): void
    {
        // ownership.column = 'hex', ownership.setOnCreate = 'foreign_article_id'
        // Both must receive the FE user UID (1) on creation
        $this->registerResource(
            'relation-write-colors',
            array_merge(self::BASE_COLOR_CONFIG, ['ownership' => ['column' => 'hex', 'setOnCreate' => 'foreign_article_id']])
        );

        $response = $this->executeApiWriteRequestAs('POST', '/_api/relation-write-articles', 1, [
            'title' => 'SetOnCreate Article',
            'color_id' => ['name' => 'TrackColor'],
        ]);
        $colorUid = (int)basename($this->decodeResponseBody($response)['color_id']);

        $colorRow = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['hex', 'foreign_article_id'], 'tx_myext_domain_model_color', ['uid' => $colorUid])
            ->fetchAssociative() ?: [];

        self::assertSame('1', (string)$colorRow['hex'], 'ownership.column must receive FE user UID');
        self::assertSame(1, (int)$colorRow['foreign_article_id'], 'ownership.setOnCreate must receive FE user UID');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->registerResources();
    }

    private function registerResources(): void
    {
        $this->registerResource('relation-write-colors', self::BASE_COLOR_CONFIG);
        $this->registerResource('relation-write-articles', self::BASE_ARTICLE_CONFIG);
        $this->registerResource('relation-write-categories', self::BASE_CATEGORY_CONFIG);
    }
}
