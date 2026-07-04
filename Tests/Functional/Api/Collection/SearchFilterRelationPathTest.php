<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Collection;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Filter\RelationResolver;
use MaikSchneider\TcaApi\Filter\RelationSubqueryBuilder;
use MaikSchneider\TcaApi\Filter\SearchFilter;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for #154: SearchFilter's `columns` list may mix the resource's own
 * columns with relation-path (dotted) columns. A value LIKE-matches across all of them,
 * OR-ed together — the resource's own columns are matched directly, related columns via
 * a `t.uid IN (relation subquery)`.
 *
 * Fixtures:
 *   Article 1 "First Article"   colour Red   categories [PHP, TYPO3]
 *   Article 2 "Second Article"  colour Blue  categories [API]
 *   Article 3 "Third Article"   colour —     categories []
 *   Article 4 "Hidden Article"  hidden (excluded)
 */
final class SearchFilterRelationPathTest extends ApiFunctionalTestCase
{
    private const RESOURCE = 'articles-cross-search';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm.csv');

        $this->registerResource(self::RESOURCE, [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => self::RESOURCE,
                'resourceType' => 'Article',
                'operations'   => ['list'],
            ],
            'columns' => ['title' => ['groups' => ['list', 'show']]],
            'filters' => [
                'q' => [SearchFilter::class, ['columns' => [
                    'title',             // own column
                    'categories.title',  // MM hop
                    'color_id.name',     // FK hop
                ]]],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    /** @return list<int> */
    private function search(string $value, string $resource = self::RESOURCE): array
    {
        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/' . $resource, ['filters' => ['q' => $value]]),
        );

        return array_values(array_map(static fn (array $m): int => $m['uid'], $body['hydra:member']));
    }

    public function testMatchesOwnColumn(): void
    {
        // "First" only appears in article 1's own title.
        self::assertSame([1], $this->search('First'));
    }

    public function testPreResolvedSearchPathsProduceSameResult(): void
    {
        // registerResource() skips preResolve; register with a filterMap so the search
        // paths are resolved at build time (the production path) and used in apply().
        $builder = new RelationSubqueryBuilder(
            GeneralUtility::makeInstance(ConnectionPool::class),
            new RelationResolver(),
        );
        $definition = ApiDefinition::fromArray(
            [
                'general' => [
                    'table'        => 'tx_myext_domain_model_article',
                    'resourceName' => 'articles-cross-search-pre',
                    'resourceType' => 'Article',
                    'operations'   => ['list'],
                ],
                'columns' => ['title' => ['groups' => ['list', 'show']]],
                'filters' => [
                    'q' => [SearchFilter::class, ['columns' => ['title', 'categories.title', 'color_id.name']]],
                ],
                'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
            ],
            [SearchFilter::class => new SearchFilter($builder)],
        );
        $this->getApiRegistry()->register('articles-cross-search-pre', $definition);

        self::assertSame([2], $this->search('API', 'articles-cross-search-pre'));
        self::assertSame([1], $this->search('Red', 'articles-cross-search-pre'));
    }

    public function testMatchesMmRelatedColumn(): void
    {
        // "API" appears only as category title of article 2 (not in its own title/colour).
        self::assertSame([2], $this->search('API'));
    }

    public function testMatchesFkRelatedColumn(): void
    {
        // "Blue" appears only as article 2's colour name.
        self::assertSame([2], $this->search('Blue'));
    }

    public function testMatchesMmCategoryOnMultiCategoryRow(): void
    {
        // Article 1 has [PHP, TYPO3]; matching the second category still returns it.
        self::assertSame([1], $this->search('TYPO3'));
    }

    public function testOwnColumnBranchStillMatchesAllRows(): void
    {
        // "Article" is in every (visible) title — proves the own-column branch is intact
        // and the OR does not narrow it. Article 4 is hidden.
        self::assertSame([1, 2, 3], $this->search('Article'));
    }

    public function testNoMatchAcrossAnyColumnReturnsEmpty(): void
    {
        self::assertSame([], $this->search('zzz-nothing'));
    }

    public function testWordStartMatchModeAppliesToRelatedColumns(): void
    {
        $this->registerResource('articles-cross-search-ws', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'articles-cross-search-ws',
                'resourceType' => 'Article',
                'operations'   => ['list'],
            ],
            'columns' => ['title' => ['groups' => ['list', 'show']]],
            'filters' => [
                'q' => [SearchFilter::class, [
                    'columns' => ['title', 'categories.title'],
                    'match'   => 'word_start',
                ]],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        // "TYPO" is a prefix of category "TYPO3" → article 1 matches.
        self::assertSame([1], $this->search('TYPO', 'articles-cross-search-ws'));
        // "YPO" is not a prefix of any own title or category → no match (word_start).
        self::assertSame([], $this->search('YPO', 'articles-cross-search-ws'));
    }
}
