<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\QueryCounting\CountingDriverMiddleware;
use MaikSchneider\TcaApi\Tests\Functional\QueryCounting\QueryCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Guards relation preloading against N+1 regressions.
 *
 * Every relation column must cost a fixed number of queries per response,
 * whether it is rendered as IRIs or embedded, and whether it belongs to the
 * requested resource or to a record embedded from it. The assertions compare
 * two responses over *disjoint* record sets of different size, so any
 * per-record query shows up as a difference.
 *
 * Fixture data (relations_bulk.csv), for i = 0…11:
 *
 *   article 700+i  color_id → color 700+i
 *                  categories (MM) → category 700+i
 *                  related_colors (UID list) → color 740+i
 *                  related_items (multi-table) → color 740+i
 *                  related_colors_mm_grp (group MM) → color 740+i
 *                  related_items_inline (foreign_field) ← color 720+i
 *   color 720+i    category_id → category 720+i
 *
 *   article 700    parent_id → article 712, which is categorised as category 700
 */
final class RelationQueryCountTest extends ApiFunctionalTestCase
{
    protected array $configurationToUseInTestInstance = [
        'DB' => [
            'Connections' => [
                'Default' => [
                    'driverMiddlewares' => [
                        'tca-api/query-counter' => ['target' => CountingDriverMiddleware::class],
                    ],
                ],
            ],
        ],
    ];

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
                ],
                'size'             => 10,
                'maxitems'         => 9999,
            ],
        ];
        $this->get(TcaSchemaFactory::class)->load($GLOBALS['TCA'], true);

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/relations_bulk.csv');
    }

    public static function articleColumnProvider(): iterable
    {
        foreach (['categories', 'related_colors', 'related_items', 'related_colors_mm_grp', 'related_items_inline', 'color_id'] as $column) {
            yield $column . ' as IRIs' => [$column, []];
            yield $column . ' embedded' => [$column, ['embed' => true]];
        }
        yield 'related_items_inline embedded two levels' => ['related_items_inline', ['embed' => ['depth' => 2], 'resourceName' => 'qc-colors']];
    }

    #[DataProvider('articleColumnProvider')]
    public function testArticleRelationColumnDoesNotAddQueriesPerRecord(string $column, array $columnConfig): void
    {
        $this->registerCategoryResource();
        $this->registerColorResource();
        $this->registerArticleResource([$column => ['groups' => ['list', 'show']] + $columnConfig]);

        self::assertSame(0, $this->marginalQueryCost('/_api/qc-articles'));
    }

    public function testReverseMmColumnDoesNotAddQueriesPerRecord(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();

        self::assertSame(0, $this->marginalQueryCost('/_api/qc-categories'));
    }

    public function testReverseMmColumnOnEmbeddedRecordDoesNotAddQueriesPerRecord(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource(['categories' => ['groups' => ['list', 'show'], 'embed' => true, 'resourceName' => 'qc-categories']]);

        self::assertSame(0, $this->marginalQueryCost('/_api/qc-articles'));
    }

    public function testReverseMmColumnStillSerializesUnderPreload(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();

        $members = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/qc-categories', ['itemsPerPage' => 3]),
        )['hydra:member'];

        self::assertSame(['/_api/articles/700', '/_api/articles/712'], $members[0]['items']);
        self::assertSame(['/_api/articles/701'], $members[1]['items']);
        self::assertSame(['/_api/articles/702'], $members[2]['items']);
    }

    /**
     * An embedded record of the requested table is not part of the requested page,
     * so its relations must not be read from the page's preload as "none".
     */
    public function testEmbeddedRecordOfSameTableKeepsItsRelations(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource([
            'categories' => ['groups' => ['list', 'show'], 'embed' => true, 'resourceName' => 'qc-categories'],
            'parent_id'  => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/qc-articles/700'));

        self::assertSame(712, $body['parent_id']['uid']);
        self::assertSame(['/_api/qc-categories/700'], $body['parent_id']['categories']);
    }

    private function registerCategoryResource(): void
    {
        $this->registerResource('qc-categories', [
            'general' => [
                'table'           => 'sys_category',
                'resourceName'    => 'qc-categories',
                'resourceType'    => 'Category',
                'operations'      => ['list', 'show'],
                'maxItemsPerPage' => 100,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerColorResource(): void
    {
        $this->registerResource('qc-colors', [
            'general' => [
                'table'        => 'tx_myext_domain_model_color',
                'resourceName' => 'qc-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show'],
            ],
        ]);
    }

    private function registerArticleResource(array $columns = []): void
    {
        $this->registerResource('qc-articles', [
            'general' => [
                'table'           => 'tx_myext_domain_model_article',
                'resourceName'    => 'qc-articles',
                'resourceType'    => 'Article',
                'operations'      => ['list', 'show'],
                'maxItemsPerPage' => 100,
            ],
            'columns' => ['title' => ['groups' => ['list', 'show']]] + $columns,
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    /**
     * Queries a request spends per record, measured as the difference between a
     * 2-record page and a 6-record page over records that share no relation.
     */
    private function marginalQueryCost(string $path): int
    {
        $this->executeApiRequest($path, ['itemsPerPage' => 2, 'page' => 2]);

        QueryCounter::start();
        $this->executeApiRequest($path, ['itemsPerPage' => 2, 'page' => 1]);
        $small = QueryCounter::stop();

        QueryCounter::start();
        $this->executeApiRequest($path, ['itemsPerPage' => 6, 'page' => 2]);
        $large = QueryCounter::stop();

        return intdiv($large - $small, 4);
    }
}
