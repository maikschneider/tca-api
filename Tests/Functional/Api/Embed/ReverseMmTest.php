<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Functional tests for the reverse-side MM dispatch path.
 *
 * sys_category.items is a `type=group` field with `allowed='*'` and
 * `MM_oppositeUsage` listing the forward-side tables — the canonical TYPO3
 * example of a polymorphic reverse-MM column. Before this fix, the preloader
 * and serializer would crash with invalid SQL using `*` as a table name.
 *
 * Fixture data (sys_categories_reverse.csv + sys_category_record_mm_reverse.csv +
 *               articles.csv + colors.csv):
 *
 *   Category 10 → articles [2 (sf=1), 1 (sf=2)] + color [1 (sf=3)]
 *   Category 11 → article [1 (sf=1)]
 *   Category 12 → (no MM entries — empty items)
 *
 * sf = sorting_foreign (canonical reverse-side order column)
 */
final class ReverseMmTest extends ApiFunctionalTestCase
{
    private const CATEGORY_TABLE = 'sys_category';
    private const ARTICLE_TABLE  = 'tx_myext_domain_model_article';
    private const COLOR_TABLE    = 'tx_myext_domain_model_color';

    protected function setUp(): void
    {
        parent::setUp();

        // Simulate what TYPO3 CategoryRegistry produces for type=category fields:
        // sys_category.items as type=group wildcard with MM_oppositeUsage.
        // Set BEFORE the first schema-factory call (the factory lazily caches per-schema).
        $GLOBALS['TCA']['sys_category']['columns']['items'] = [
            'label'  => 'Items',
            'config' => [
                'type'             => 'group',
                'allowed'          => '*',
                'MM'               => 'sys_category_record_mm',
                'MM_oppositeUsage' => [
                    'tx_myext_domain_model_article' => ['categories'],
                    'tx_myext_domain_model_color'   => ['categories_rel'],
                ],
                'size'             => 10,
                'maxitems'         => 9999,
            ],
        ];

        // Force-rebuild AND re-cache the schema so the sub-request's TcaSchemaFactory picks
        // up sys_category.items — load(force=true) writes through to PhpFrontend cache.
        $this->get(TcaSchemaFactory::class)->load($GLOBALS['TCA'], true);

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_reverse.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm_reverse.csv');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function registerCategoryResource(array $columnOverrides = []): void
    {
        $this->registerResource('rm-categories', [
            'general' => [
                'table'        => self::CATEGORY_TABLE,
                'resourceName' => 'rm-categories',
                'resourceType' => 'Category',
                'operations'   => ['list', 'show'],
            ],
            'columns' => array_merge([
                'title' => ['groups' => ['list', 'show']],
                'items' => ['groups' => ['list', 'show']],
            ], $columnOverrides),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerArticleResource(): void
    {
        $this->registerResource('rm-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'rm-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => ['title' => ['groups' => ['list', 'show']]],
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerColorResource(): void
    {
        $this->registerResource('rm-colors', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'rm-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show'],
            ],
            'columns' => ['name' => ['groups' => ['list', 'show']]],
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Regression guard ──────────────────────────────────────────────────────

    /**
     * ISC-31: No SQL error using '*' as table name.
     * The old code path would produce SELECT FROM `*` and crash with a 500.
     */
    public function testNoSqlErrorWithWildcardAllowed(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();
        $this->registerColorResource();

        $response = $this->executeApiRequest('/_api/rm-categories/10');

        self::assertSame(200, $response->getStatusCode());
    }

    // ── IRI strings (no embed) ────────────────────────────────────────────────

    /**
     * ISC-26: GET /api/categories/1 returns items as IRI list.
     */
    public function testReverseMmWithoutEmbedReturnsIriStrings(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();
        $this->registerColorResource();

        $response = $this->executeApiRequest('/_api/rm-categories/10');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['items']);
        self::assertCount(3, $body['items']);

        foreach ($body['items'] as $iri) {
            self::assertIsString($iri);
            self::assertMatchesRegularExpression('#/_api/[^/]+/\d+$#', $iri);
        }
    }

    // ── sorting_foreign order ─────────────────────────────────────────────────

    /**
     * ISC-27: Items arrive in sorting_foreign order (reverse-side canonical ordering).
     */
    public function testReverseMmItemsOrderedBySortingForeign(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();
        $this->registerColorResource();

        $response = $this->executeApiRequest('/_api/rm-categories/10');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(3, $body['items']);

        // sorting_foreign: article 2 → 1, article 1 → 2, color 1 → 3
        self::assertStringEndsWith('articles/2', $body['items'][0]);
        self::assertStringEndsWith('articles/1', $body['items'][1]);
        self::assertStringEndsWith('colors/1', $body['items'][2]);
    }

    // ── Multi-table mixing ────────────────────────────────────────────────────

    /**
     * ISC-28: Items span multiple forward-side tables (articles + colors).
     */
    public function testReverseMmIncludesBothForwardSideTables(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();
        $this->registerColorResource();

        $response = $this->executeApiRequest('/_api/rm-categories/10');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());

        $iris        = $body['items'];
        $articleIris = array_values(array_filter($iris, fn (string $iri) => str_contains($iri, '/articles/')));
        $colorIris   = array_values(array_filter($iris, fn (string $iri) => str_contains($iri, '/colors/')));

        self::assertCount(2, $articleIris);
        self::assertCount(1, $colorIris);
    }

    // ── Full embed ────────────────────────────────────────────────────────────

    /**
     * ISC-27 (embed variant): embed=items returns fully serialized records.
     */
    public function testReverseMmWithEmbedReturnsFullRecords(): void
    {
        $this->registerCategoryResource([
            'items' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);
        $this->registerArticleResource();
        $this->registerColorResource();

        $response = $this->executeApiRequest('/_api/rm-categories/11');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['items']);
        self::assertCount(1, $body['items']);

        $embedded = $body['items'][0];
        self::assertIsArray($embedded);
        self::assertArrayHasKey('@id', $embedded);
        self::assertArrayHasKey('@type', $embedded);
        self::assertArrayHasKey('uid', $embedded);
        self::assertSame(1, $embedded['uid']);
        self::assertSame('First Article', $embedded['title']);
    }

    // ── Empty category ────────────────────────────────────────────────────────

    /**
     * Category with no MM entries returns empty items array.
     */
    public function testReverseMmEmptyCategoryReturnsEmptyItems(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/rm-categories/12');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['items']);
    }

    // ── List operation ────────────────────────────────────────────────────────

    /**
     * ISC-6: Serializer routes wildcard through multi-table path on list operation too.
     */
    public function testReverseMmListOperationReturnsItemsForEachCategory(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();
        $this->registerColorResource();

        $response = $this->executeApiRequest('/_api/rm-categories');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(3, $body['hydra:member']);

        $byUid = [];
        foreach ($body['hydra:member'] as $cat) {
            $byUid[$cat['uid']] = $cat;
        }

        self::assertCount(3, $byUid[10]['items']);
        self::assertCount(1, $byUid[11]['items']);
        self::assertCount(0, $byUid[12]['items']);
    }

    // ── ISC-32 regression guard ───────────────────────────────────────────────

    /**
     * ISC-32: Forward-side categories relation (articles.categories) still works.
     */
    public function testForwardSideCategoriesRelationUnaffected(): void
    {
        $this->registerResource('rm-cats-fwd', [
            'general' => [
                'table'        => self::CATEGORY_TABLE,
                'resourceName' => 'rm-cats-fwd',
                'resourceType' => 'Category',
                'operations'   => ['list', 'show'],
            ],
            'columns' => ['title' => ['groups' => ['list', 'show']]],
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        // sys_categories.csv has UIDs 1, 2, 3 — not our reverse-MM categories
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm.csv');

        $response = $this->executeApiRequest('/_api/rm-cats-fwd/1');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('PHP', $body['title']);
    }
}
