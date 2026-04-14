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
    private const INITIAL_CATEGORY_UID_MAX = 3;

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

    public function testPostWithCategoryCreatePayloadCreatesAndAssignsCategory(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'Article With New Category',
            'categories' => [
                ['title' => 'Created By Post'],
            ],
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertCount(1, $body['categories']);
        self::assertGreaterThan(self::INITIAL_CATEGORY_UID_MAX, $body['categories'][0]['uid']);
    }

    public function testPutWithCategoryCreatePayloadCanMixNewAndExistingRelations(): void
    {
        $response = $this->executeApiWriteRequestAs('PUT', '/_api/articles/3', 1, [
            'title' => 'Third Article',
            'categories' => [
                2,
                ['title' => 'Created By Put'],
            ],
        ]);
        $body = $this->decodeResponseBody($response);

        $uids = array_column($body['categories'], 'uid');
        self::assertContains(2, $uids);
        self::assertCount(2, $uids);
        self::assertGreaterThan(self::INITIAL_CATEGORY_UID_MAX, max($uids));
    }

    public function testPatchWithCategoryCreatePayloadCanMixNewAndExistingRelations(): void
    {
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/articles/3', 1, [
            'categories' => [
                1,
                ['title' => 'Created By Patch'],
            ],
        ]);
        $body = $this->decodeResponseBody($response);

        $uids = array_column($body['categories'], 'uid');
        self::assertContains(1, $uids);
        self::assertCount(2, $uids);
        self::assertGreaterThan(self::INITIAL_CATEGORY_UID_MAX, max($uids));
    }
}
