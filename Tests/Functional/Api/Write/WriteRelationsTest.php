<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for writing relation fields via POST / PUT / PATCH.
 *
 * color_id and categories now have groups including 'create'/'update' in Articles.php config.
 * All tests must fail until the config and write pipeline are updated.
 *
 * Fixture baseline:
 *   Article 1 → color_id=1 (Red), categories=[1 (PHP), 2 (TYPO3)]
 *   Article 2 → color_id=2 (Blue), categories=[3 (API)]
 *   Article 3 → color_id=0 (none), categories=[]
 *   Colors: 1=Red, 2=Blue
 *   SysCategories: 1=PHP, 2=TYPO3, 3=API
 */
final class WriteRelationsTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
    }

    // ── hasOne (color_id) ────────────────────────────────────────────────────

    public function testPostWithColorIdSetsColor(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'Colored Article',
            'color_id' => 1,
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('color', $body);
        self::assertIsArray($body['color']);
        self::assertSame(1, $body['color']['uid']);
    }

    public function testPostWithColorIdPersistedInDatabase(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'Persisted Color',
            'color_id' => 2,
        ]);
        $uid = $this->decodeResponseBody($response)['uid'];

        $getResponse = $this->executeApiRequest('/_api/articles/' . $uid);
        $body = $this->decodeResponseBody($getResponse);

        self::assertSame(2, $body['color']['uid']);
    }

    public function testPutUpdatesColorId(): void
    {
        // Article 1 has color_id=1 (Red) → update to color_id=2 (Blue)
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, [
            'title' => 'First Article',
            'color_id' => 2,
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(2, $body['color']['uid']);
    }

    public function testPatchWithZeroColorIdRemovesColor(): void
    {
        // Article 1 has color_id=1 → patch to color_id=0 (remove)
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/articles/1', 1, [
            'color_id' => 0,
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('color', $body);
        self::assertNull($body['color']);
    }

    // ── manyToMany (sys_category) ────────────────────────────────────────────

    public function testPostWithCategoriesSetsCategoryRelations(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'Categorized Article',
            'categories' => [1, 2],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('categories', $body);
        self::assertCount(2, $body['categories']);
    }

    public function testPostWithCategoriesResponseContainsCategoryUids(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'PHP Article',
            'categories' => [1],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['categories'][0]['uid']);
        self::assertSame('SysCategory', $body['categories'][0]['@type']);
    }

    public function testPutWithCategoriesReplacesCategoryRelations(): void
    {
        // Article 1 has categories [1, 2] → PUT replaces with [3]
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, [
            'title' => 'First Article',
            'categories' => [3],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertCount(1, $body['categories']);
        self::assertSame(3, $body['categories'][0]['uid']);
    }

    public function testPatchWithEmptyCategoriesRemovesAllCategories(): void
    {
        // Article 1 has categories [1, 2] → patch with [] removes them
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/articles/1', 1, [
            'categories' => [],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('categories', $body);
        self::assertSame([], $body['categories']);
    }

    public function testPatchCategoriesPersistedInDatabase(): void
    {
        $this->executeApiWriteRequestAs('PATCH', '/_api/articles/3', 1, [
            'categories' => [2],
        ]);

        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/articles/3'));

        self::assertCount(1, $body['categories']);
        self::assertSame(2, $body['categories'][0]['uid']);
    }

    // ── Inline object creation (hasOne) ──────────────────────────────────────

    public function testPostWithNewColorObjectCreatesColorAndLinks(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title'    => 'Fresh Color Article',
            'color_id' => ['name' => 'Fresh'],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertArrayHasKey('color', $body);
        self::assertIsArray($body['color']);
        self::assertGreaterThan(2, $body['color']['uid'], 'New color UID should be > 2 (fixtures have 1,2)');
    }

    public function testPostWithNewColorObjectColorPersistedInDatabase(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title'    => 'Persisted New Color',
            'color_id' => ['name' => 'PersistMe'],
        ]);
        $articleUid = $this->decodeResponseBody($response)['uid'];

        $getBody = $this->decodeResponseBody($this->executeApiRequest('/_api/articles/' . $articleUid));

        self::assertSame('Color', $getBody['color']['@type']);
        self::assertGreaterThan(2, $getBody['color']['uid']);
    }

    public function testPutWithNewColorObjectReplacesRelation(): void
    {
        // Article 1 currently has color_id=1 (Red)
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, [
            'title'    => 'First Article',
            'color_id' => ['name' => 'Replaced'],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['color']);
        self::assertGreaterThan(2, $body['color']['uid'], 'New color should have uid > 2');
    }

    // ── Inline object creation (hasMany / MM) ─────────────────────────────────

    public function testPostWithNewCategoryObjectCreatesCategoryAndLinks(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title'      => 'New Cat Article',
            'categories' => [['title' => 'Inline Category']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $body['categories']);
        self::assertGreaterThan(3, $body['categories'][0]['uid'], 'New cat UID should be > 3 (fixtures have 1-3)');
    }

    public function testPostWithMixedCategoriesMixesNewAndExisting(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title'      => 'Mixed Cat Article',
            'categories' => [1, ['title' => 'Mixed New']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(2, $body['categories']);

        $uids = array_column($body['categories'], 'uid');
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
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/articles/3', 1, [
            'categories' => [2, ['title' => 'Patch New Cat']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['categories']);

        $uids = array_column($body['categories'], 'uid');
        self::assertContains(2, $uids);
    }

    public function testPostWithNewCategoryObjectCategoryPersistedInDatabase(): void
    {
        $response   = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title'      => 'Persist Cat Article',
            'categories' => [['title' => 'PersistCat']],
        ]);
        $articleUid = $this->decodeResponseBody($response)['uid'];

        $getBody = $this->decodeResponseBody($this->executeApiRequest('/_api/articles/' . $articleUid));

        self::assertCount(1, $getBody['categories']);
        self::assertSame('SysCategory', $getBody['categories'][0]['@type']);
        self::assertGreaterThan(3, $getBody['categories'][0]['uid']);
    }

    public function testPutWithNewCategoryObjectReplacesCategoryRelations(): void
    {
        // Article 1 has categories [1, 2] → PUT replaces with 1 new category
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/articles/1', 1, [
            'title'      => 'First Article',
            'categories' => [['title' => 'PutNewCat']],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['categories']);
        self::assertGreaterThan(3, $body['categories'][0]['uid'], 'New category UID should be > 3');
    }

    // ── Sub-entity ownership injection (prepareChildData) ─────────────────────

    public function testPostNewSubEntityGetsOwnerColumnInjected(): void
    {
        // Register Colors with ownership.column = 'hex' so FE user UID is injected there
        $this->configureColorsWithOwnership('hex');

        $response   = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title'    => 'Owner Article',
            'color_id' => ['name' => 'OwnedColor'],
        ]);
        $colorUid = $this->decodeResponseBody($response)['color']['uid'];

        $colorRow = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['hex'], 'tx_myext_domain_model_color', ['uid' => $colorUid])
            ->fetchAssociative();

        // FE user uid=1 must be injected into the ownership column
        self::assertSame('1', (string)$colorRow['hex']);
    }

    public function testPostNewSubEntityClientOwnershipValueIsStripped(): void
    {
        // Client attempts to set hex='hacker' — server must overwrite with FE user UID
        $this->configureColorsWithOwnership('hex');

        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title'    => 'Strip Client Value',
            'color_id' => ['name' => 'Color', 'hex' => 'hacker'],
        ]);
        $colorUid = $this->decodeResponseBody($response)['color']['uid'];

        $colorRow = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['hex'], 'tx_myext_domain_model_color', ['uid' => $colorUid])
            ->fetchAssociative();

        self::assertSame('1', (string)$colorRow['hex'], 'Client hex value must be stripped and replaced by server-injected FE user UID');
    }

    public function testPostNewSubEntityGetsSetOnCreateColumnInjected(): void
    {
        // ownership.column = 'hex', ownership.setOnCreate = 'foreign_article_id'
        // Both must receive the FE user UID (1) on creation
        $this->configureColorsWithOwnership('hex', 'foreign_article_id');

        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title'    => 'SetOnCreate Article',
            'color_id' => ['name' => 'TrackColor'],
        ]);
        $colorUid = $this->decodeResponseBody($response)['color']['uid'];

        $colorRow = $this->getConnectionPool()
            ->getConnectionForTable('tx_myext_domain_model_color')
            ->select(['hex', 'foreign_article_id'], 'tx_myext_domain_model_color', ['uid' => $colorUid])
            ->fetchAssociative();

        self::assertSame('1', (string)$colorRow['hex'], 'ownership.column must receive FE user UID');
        self::assertSame(1, (int)$colorRow['foreign_article_id'], 'ownership.setOnCreate must receive FE user UID');
    }

    /**
     * Ensure a color resource with ownership columns is returned FIRST by
     * ApiRegistry::getByTable() — even after Bootstrap::init() re-runs
     * ext_localconf.php during executeFrontendSubRequest().
     *
     * Bootstrap re-registers file-based resources (articles, colors, …) but
     * never touches 'colors-with-ownership' (no matching TcaApi PHP file).
     * PHP arrays preserve insertion order on key-updates, so our owned
     * resource stays at index 0 and getByTable() returns it before 'colors'.
     */
    private function configureColorsWithOwnership(string $ownerColumn, ?string $setOnCreate = null): void
    {
        $registry = $this->getApiRegistry();
        $snapshot = $registry->getAll();
        $registry->reset();

        // Build a color resource config with ownership columns
        $ownedConfig = [
            'general' => [
                'table'        => 'tx_myext_domain_model_color',
                'resourceName' => 'colors-with-ownership',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show', 'create'],
            ],
            'columns' => [
                'name'               => ['groups' => ['list', 'show', 'create']],
                'hex'                => ['groups' => ['list', 'show', 'create']],
                'foreign_article_id' => ['groups' => ['create']],
            ],
            'ownership' => ['column' => $ownerColumn],
        ];

        if ($setOnCreate !== null) {
            $ownedConfig['ownership']['setOnCreate'] = $setOnCreate;
        }

        // Register FIRST — Bootstrap::init() will later re-register file-based
        // resources in-place (preserving order) but never touches this key.
        $this->registerResource('colors-with-ownership', $ownedConfig);

        foreach ($snapshot as $name => $config) {
            $registry->register($name, $config);
        }
    }
}
