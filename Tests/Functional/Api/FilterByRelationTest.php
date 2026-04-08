<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for filtering collections by relation fields.
 *
 * RED phase: color_id and categories are not in the Articles.php filters config.
 * MM filtering (categories) requires a new query strategy not yet implemented.
 *
 * Filter syntax: ?filters[column]=value
 *
 * Fixture baseline:
 *   Article 1 → color_id=1 (Red),  categories=[1 (PHP), 2 (TYPO3)]
 *   Article 2 → color_id=2 (Blue), categories=[3 (API)]
 *   Article 3 → color_id=0 (none), categories=[]
 *   Article 4 → Hidden (excluded from all results)
 */
final class FilterByRelationTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_category_record_mm.csv');
    }

    // ── hasOne (color_id) ────────────────────────────────────────────────────

    public function testFilterByColorIdReturnsMatchingArticles(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['color_id' => 1]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(1, $body['hydra:member'][0]['uid']);
    }

    public function testFilterByColorIdUpdatesTotalItems(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['color_id' => 2]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
    }

    public function testFilterByColorIdWithNonExistentValueReturnsEmpty(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['color_id' => 999]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(0, $body['hydra:totalItems']);
        self::assertSame([], $body['hydra:member']);
    }

    public function testFilterByColorIdZeroReturnsArticlesWithoutColor(): void
    {
        // Article 3 has color_id=0 (no color assigned)
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['color_id' => 0]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(3, $body['hydra:member'][0]['uid']);
    }

    public function testFilterByColorIdWithTitleMismatchReturnsEmpty(): void
    {
        // color_id=2 matches Article 2, but title "First Article" does not match Article 2
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['color_id' => 2, 'title' => 'First Article']]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(0, $body['hydra:totalItems']);
    }

    // ── manyToMany (categories) ──────────────────────────────────────────────

    public function testFilterByCategoryReturnsMatchingArticles(): void
    {
        // Category 3 (API) is only on Article 2
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['categories' => 3]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(2, $body['hydra:member'][0]['uid']);
    }

    public function testFilterByCategoryUpdatesTotalItems(): void
    {
        // Category 1 (PHP) is on Article 1 only
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['categories' => 1]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
    }

    public function testFilterByCategoryWithNoMatchReturnsEmpty(): void
    {
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['categories' => 999]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(0, $body['hydra:totalItems']);
        self::assertSame([], $body['hydra:member']);
    }

    public function testFilterByCategoryMatchesArticleWithMultipleCategories(): void
    {
        // Article 1 has categories [1 (PHP), 2 (TYPO3)] — filtering by 2 should still return it
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['categories' => 2]]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['hydra:totalItems']);
        self::assertSame(1, $body['hydra:member'][0]['uid']);
    }

    public function testFilterByCategoryExcludesArticlesWithoutThatCategory(): void
    {
        // Category 1 (PHP) is on Article 1; Article 2 (only API) and Article 3 (none) must not appear
        $response = $this->executeApiRequest('/_api/articles', ['filters' => ['categories' => 1]]);
        $body = $this->decodeResponseBody($response);

        $uids = array_column($body['hydra:member'], 'uid');
        self::assertNotContains(2, $uids);
        self::assertNotContains(3, $uids);
    }
}
