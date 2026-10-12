<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Embedded records limited by "embed.columns", and the unbounded reverse relation
 * sys_category.items left out of embedded default-mode records.
 *
 * Fixture data (articles.csv + colors.csv + sys_categories_reverse.csv +
 *               sys_category_record_mm_reverse.csv):
 *
 *   Article 1 → categories [21, 20] (MM sorting), color 1 (Red)
 *   Article 2 → categories [20],                   color 2 (Blue)
 *
 * Shipped fixture resources claim sys_category and the colour table, so every
 * relation names its resource: an unregistered name stands for a table without
 * an API registration, which embeds through a synthesized default-mode config.
 *   Category 20 → items [article 2, article 1, color 1]
 *   Category 21 → items [article 1]
 */
final class EmbedColumnsTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            ],
        ];
        $this->get(TcaSchemaFactory::class)->load($GLOBALS['TCA'], true);

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_reverse.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm_reverse.csv');
    }

    private function registerArticles(array $columns): void
    {
        $this->registerResource('ec-articles', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'ec-articles',
                'resourceType' => 'EcArticle',
                'operations'   => ['list', 'show'],
            ],
            'columns' => ['title' => ['groups' => ['list', 'show']]] + array_map(
                static fn (array $column) => $column + ['groups' => ['list', 'show']],
                $columns,
            ),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerCategories(array $columns = []): void
    {
        $this->registerResource('ec-categories', [
            'general' => [
                'table'        => 'sys_category',
                'resourceName' => 'ec-categories',
                'resourceType' => 'EcCategory',
                'operations'   => ['list', 'show'],
            ],
            'columns' => $columns,
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchArticles(): array
    {
        $response = $this->executeApiRequest('/_api/ec-articles');
        self::assertSame(200, $response->getStatusCode());

        $byUid = [];
        foreach ($this->decodeResponseBody($response)['hydra:member'] as $member) {
            $byUid[$member['uid']] = $member;
        }

        return $byUid;
    }

    // ── Unbounded reverse relation ────────────────────────────────────────────

    public function testEmbeddedUnregisteredCategoryLeavesOutItems(): void
    {
        $this->registerArticles(['categories' => ['resourceName' => 'unregistered', 'embed' => true]]);

        $categories = $this->fetchArticles()[1]['categories'];

        self::assertCount(2, $categories);
        self::assertSame('ReverseMM Category B', $categories[0]['title']);
        self::assertArrayHasKey('parent', $categories[0]);
        self::assertArrayNotHasKey('items', $categories[0]);
        self::assertArrayNotHasKey('items', $categories[1]);
    }

    public function testEmbeddedDefaultModeCategoryLeavesOutItemsButItsOwnEndpointKeepsThem(): void
    {
        $this->registerArticles(['categories' => ['resourceName' => 'ec-categories', 'embed' => true]]);
        $this->registerCategories();

        $embedded = $this->fetchArticles()[2]['categories'][0];
        self::assertSame('/_api/ec-categories/20', $embedded['@id']);
        self::assertArrayNotHasKey('items', $embedded);

        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/ec-categories/20'));
        self::assertCount(3, $body['items']);
    }

    public function testDeclaredReverseRelationStaysOnEmbeddedDefaultModeRecord(): void
    {
        $this->registerArticles(['categories' => ['resourceName' => 'ec-categories', 'embed' => true]]);
        $this->registerCategories(['items' => []]);

        $embedded = $this->fetchArticles()[2]['categories'][0];

        self::assertSame(20, $embedded['uid']);
        self::assertCount(3, $embedded['items']);
    }

    // ── embed.columns ─────────────────────────────────────────────────────────

    public function testEmbedColumnsLimitAnEmbeddedHasManyRecord(): void
    {
        $this->registerArticles(['categories' => ['resourceName' => 'unregistered', 'embed' => ['columns' => ['title']]]]);

        $categories = $this->fetchArticles()[1]['categories'];

        self::assertSame(
            [
                ['@type' => 'SysCategory', 'uid' => 21, 'title' => 'ReverseMM Category B'],
                ['@type' => 'SysCategory', 'uid' => 20, 'title' => 'ReverseMM Category A'],
            ],
            $categories,
        );
    }

    public function testEmbedColumnsLimitAnEmbeddedHasOneRecord(): void
    {
        $this->registerArticles(['color_id' => ['resourceName' => 'unregistered', 'embed' => ['columns' => ['name']]]]);

        self::assertSame(
            ['@type' => 'TxMyextDomainModelColor', 'uid' => 1, 'name' => 'Red'],
            $this->fetchArticles()[1]['color_id'],
        );
    }

    public function testEmbedColumnsCanNameTheReverseRelation(): void
    {
        $this->registerArticles(['categories' => ['resourceName' => 'unregistered', 'embed' => ['columns' => ['title', 'items']]]]);

        $category = $this->fetchArticles()[2]['categories'][0];

        self::assertSame(['@type', 'uid', 'title', 'items'], array_keys($category));
        self::assertCount(3, $category['items']);
    }

    public function testEmbedColumnsCannotExposeColumnsTheRelatedResourceHides(): void
    {
        $this->registerArticles(['categories' => ['resourceName' => 'ec-categories', 'embed' => ['columns' => ['title', 'parent']]]]);
        $this->registerCategories(['title' => ['groups' => ['list', 'show']]]);

        self::assertSame(
            ['@type' => 'EcCategory', '@id' => '/_api/ec-categories/20', 'uid' => 20, 'title' => 'ReverseMM Category A'],
            $this->fetchArticles()[2]['categories'][0],
        );
    }

    public function testEmbedColumnsDescribeTheEmbeddedObjectInOpenApi(): void
    {
        $this->registerArticles([
            'categories' => ['resourceName' => 'unregistered', 'embed' => ['columns' => ['title']]],
            'color_id'   => ['resourceName' => 'colors', 'embed' => ['columns' => ['name']]],
        ]);

        $spec       = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'));
        $properties = $spec['components']['schemas']['EcArticleRead']['properties'];

        self::assertSame('array', $properties['categories']['type']);
        self::assertSame(['@type', 'uid', 'title'], array_keys($properties['categories']['items']['properties']));
        self::assertSame(['@type', '@id', 'uid', 'name'], array_keys($properties['color_id']['oneOf'][0]['properties']));
        self::assertSame(['type' => 'null'], $properties['color_id']['oneOf'][1]);
    }
}
