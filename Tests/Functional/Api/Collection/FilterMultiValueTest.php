<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Collection;

use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\MmFilter;
use MaikSchneider\TcaApi\Filter\PartialFilter;
use MaikSchneider\TcaApi\Filter\RangeFilter;
use MaikSchneider\TcaApi\Filter\SearchFilter;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Functional tests for multi-value filter values (IN / NOT IN) and the `negate` option.
 *
 * Fixture baseline:
 *   Article 1 "First Article"  color_id=1 (Red)  categories=[1 (PHP), 2 (TYPO3)]
 *   Article 2 "Second Article" color_id=2 (Blue) categories=[3 (API)]
 *   Article 3 "Third Article"  color_id=0        categories=[]
 *   Article 4 "Hidden Article" hidden=1
 */
final class FilterMultiValueTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm.csv');
    }

    // ── ExactFilter ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $options */
    #[DataProvider('clearedFilterValues')]
    public function testClearedFilterAppliesNoConstraint(string $column, bool $negate, mixed $value, array $options): void
    {
        $this->registerArticles('cleared-filter', [
            $column => [ExactFilter::class, array_merge($options, ['negate' => $negate])],
        ]);

        $response = $this->executeApiRequest('/_api/cleared-filter', ['filters' => [$column => $value]]);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponseBody($response);

        // Include records both with and without categories, but never hidden records.
        self::assertSame(3, $body['hydra:totalItems']);
        self::assertSame(['First Article', 'Second Article', 'Third Article'], $this->titles($body));
    }

    /** @return iterable<string, array{string, bool, mixed, array<string, mixed>}> */
    public static function clearedFilterValues(): iterable
    {
        foreach (['color_id', 'categories.title'] as $column) {
            foreach ([false, true] as $negate) {
                $label = $column . ($negate ? ' negated' : ' positive');
                // PHP parses filters[column][]= as [''], not [].
                yield $label . ' blank array' => [$column, $negate, [''], []];
                yield $label . ' whitespace array' => [$column, $negate, ['  '], []];
                yield $label . ' empty separator value' => [$column, $negate, '', ['separator' => ',']];
            }
        }
    }

    public function testBlankEntriesDoNotAddZeroToExactFilter(): void
    {
        $this->registerArticles('mixed-blank-exact', ['color_id' => ExactFilter::class]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/mixed-blank-exact', ['filters' => ['color_id' => ['', '1', '  ']]]),
        );

        self::assertSame(['First Article'], $this->titles($body));
    }

    public function testZeroIsStillAnExactFilterValue(): void
    {
        $this->registerArticles('zero-exact', ['color_id' => ExactFilter::class]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/zero-exact', ['filters' => ['color_id' => ['', '0']]]),
        );

        self::assertSame(['Third Article'], $this->titles($body));
    }

    public function testExactFilterWithListMatchesAnyValue(): void
    {
        $this->registerArticles('multi-exact', ['color_id' => ExactFilter::class]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/multi-exact', ['filters' => ['color_id' => ['1', '2']]]),
        );

        self::assertSame(2, $body['hydra:totalItems']);
        self::assertSame(['First Article', 'Second Article'], $this->titles($body));
    }

    public function testExactFilterWithSingleEntryListBehavesLikeAScalar(): void
    {
        $this->registerArticles('single-exact', ['color_id' => ExactFilter::class]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/single-exact', ['filters' => ['color_id' => ['2']]]),
        );

        self::assertSame(['Second Article'], $this->titles($body));
    }

    public function testNegatedExactFilterExcludesTheValue(): void
    {
        $this->registerArticles('neq-exact', ['color_id' => [ExactFilter::class, ['negate' => true]]]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/neq-exact', ['filters' => ['color_id' => '1']]),
        );

        self::assertSame(['Second Article', 'Third Article'], $this->titles($body));
    }

    public function testNegatedExactFilterWithListExcludesEveryValue(): void
    {
        $this->registerArticles('notin-exact', ['color_id' => [ExactFilter::class, ['negate' => true]]]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/notin-exact', ['filters' => ['color_id' => ['1', '2']]]),
        );

        self::assertSame(['Third Article'], $this->titles($body));
    }

    public function testSeparatorOptionSplitsAScalarValueIntoAList(): void
    {
        $this->registerArticles('csv-exact', ['color_id' => [ExactFilter::class, ['separator' => ',']]]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/csv-exact', ['filters' => ['color_id' => '1,2']]),
        );

        self::assertSame(['First Article', 'Second Article'], $this->titles($body));
    }

    public function testMoreValuesThanAllowedIsRejectedWith400(): void
    {
        $this->registerArticles('capped-exact', ['color_id' => [ExactFilter::class, ['maxValues' => 2]]]);

        $response = $this->executeApiRequest('/_api/capped-exact', ['filters' => ['color_id' => ['1', '2', '3']]]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('accepts at most 2 values', $body['hydra:description']);
    }

    public function testOperatorMapIsRejectedForExactFilter(): void
    {
        $this->registerArticles('invalid-map', ['color_id' => ExactFilter::class]);

        $response = $this->executeApiRequest('/_api/invalid-map', ['filters' => ['color_id' => ['gte' => '1']]]);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            'Filter "color_id" expects a scalar or a list of values.',
            $this->decodeResponseBody($response)['hydra:description'],
        );
    }

    // ── LIKE filters ─────────────────────────────────────────────────────

    public function testPartialFilterWithListMatchesAnyValue(): void
    {
        $this->registerArticles('multi-partial', ['title' => PartialFilter::class]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/multi-partial', ['filters' => ['title' => ['First', 'Third']]]),
        );

        self::assertSame(['First Article', 'Third Article'], $this->titles($body));
    }

    public function testNegatedPartialFilterWithListExcludesEveryValue(): void
    {
        $this->registerArticles('not-partial', ['title' => [PartialFilter::class, ['negate' => true]]]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/not-partial', ['filters' => ['title' => ['First', 'Third']]]),
        );

        self::assertSame(['Second Article'], $this->titles($body));
    }

    public function testSearchFilterWithListMatchesAnyTerm(): void
    {
        $this->registerArticles('multi-search', ['q' => [SearchFilter::class, ['columns' => ['title']]]]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/multi-search', ['filters' => ['q' => ['First', 'Second']]]),
        );

        self::assertSame(['First Article', 'Second Article'], $this->titles($body));
    }

    // ── MmFilter ─────────────────────────────────────────────────────────

    public function testMmFilterWithListMatchesAnyCategory(): void
    {
        $this->registerArticles('multi-mm', ['categories' => MmFilter::class]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/multi-mm', ['filters' => ['categories' => ['1', '3']]]),
        );

        self::assertSame(['First Article', 'Second Article'], $this->titles($body));
    }

    public function testMmFilterMatchAllRequiresEveryCategory(): void
    {
        $this->registerArticles('all-mm', ['categories' => [MmFilter::class, ['match' => 'all']]]);

        $matchesBoth = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/all-mm', ['filters' => ['categories' => ['1', '2']]]),
        );
        self::assertSame(['First Article'], $this->titles($matchesBoth));

        $matchesNeither = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/all-mm', ['filters' => ['categories' => ['1', '3']]]),
        );
        self::assertSame(0, $matchesNeither['hydra:totalItems']);
    }

    public function testNegatedMmFilterExcludesRecordsWithThoseCategories(): void
    {
        $this->registerArticles('not-mm', ['categories' => [MmFilter::class, ['negate' => true]]]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/not-mm', ['filters' => ['categories' => ['1', '3']]]),
        );

        self::assertSame(['Third Article'], $this->titles($body));
    }

    /** @param list<string> $uids */
    #[DataProvider('equivalentMmUids')]
    public function testMmMatchAllCountsDistinctUids(array $uids, bool $negate): void
    {
        $this->registerArticles('distinct-mm', ['categories' => [MmFilter::class, ['match' => 'all', 'negate' => $negate]]]);

        $response = $this->executeApiRequest('/_api/distinct-mm', ['filters' => ['categories' => $uids]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            $negate ? ['Second Article', 'Third Article'] : ['First Article'],
            $this->titles($this->decodeResponseBody($response)),
        );
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function equivalentMmUids(): iterable
    {
        yield 'one uid' => [['1', '01'], false];
        yield 'two uids' => [['1', '01', '2', '02'], false];
        yield 'one uid negated' => [['1', '01'], true];
        yield 'two uids negated' => [['1', '01', '2', '02'], true];
    }

    #[DataProvider('invalidMmUids')]
    public function testMmFilterRejectsValuesThatAreNotUids(mixed $value): void
    {
        $this->registerArticles('invalid-mm', ['categories' => MmFilter::class]);

        $response = $this->executeApiRequest('/_api/invalid-mm', ['filters' => ['categories' => $value]]);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            'Filter "categories" expects numeric record identifiers.',
            $this->decodeResponseBody($response)['hydra:description'],
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidMmUids(): iterable
    {
        yield 'non-numeric scalar' => ['foo'];
        yield 'decimal' => ['1.9'];
        yield 'negative' => ['-1'];
        yield 'out of integer range' => ['99999999999999999999'];
        yield 'decimal in a list' => [['1', '1.9']];
        yield 'non-numeric in a list' => [['1', 'foo']];
    }

    // ── search on relations ──────────────────────────────────────────────

    public function testTwoSearchFiltersOverOneRelationPathStayIndependent(): void
    {
        $this->registerArticles('two-search', [
            'q1' => [SearchFilter::class, ['columns' => ['categories.title']]],
            'q2' => [SearchFilter::class, ['columns' => ['categories.title']]],
        ]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/two-search', ['filters' => ['q1' => 'PHP', 'q2' => 'API']]),
        );

        self::assertSame(0, $body['hydra:totalItems']);
    }

    // ── relation paths ───────────────────────────────────────────────────

    #[DataProvider('invalidRelationValues')]
    public function testRelationValidationNamesThePublicFilter(mixed $value, string $message): void
    {
        $this->registerArticles('invalid-path', ['categories.title' => [ExactFilter::class, ['maxValues' => 2]]]);

        $response = $this->executeApiRequest('/_api/invalid-path', ['filters' => ['categories.title' => $value]]);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame($message, $this->decodeResponseBody($response)['hydra:description']);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidRelationValues(): iterable
    {
        yield 'too many values' => [['PHP', 'API', 'TYPO3'], 'Filter "categories.title" accepts at most 2 values, 3 given.'];
        yield 'nested values' => [[['PHP']], 'Filter "categories.title" does not accept nested values.'];
        yield 'operator map' => [['gte' => 'PHP'], 'Filter "categories.title" expects a scalar or a list of values.'];
    }

    #[DataProvider('inactiveRangeValues')]
    public function testInactiveRelationRangeAppliesNoConstraint(mixed $value, bool $negate): void
    {
        $this->registerArticles('inactive-range', ['categories.uid' => [RangeFilter::class, ['negate' => $negate]]]);

        $response = $this->executeApiRequest('/_api/inactive-range', ['filters' => ['categories.uid' => $value]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['First Article', 'Second Article', 'Third Article'],
            $this->titles($this->decodeResponseBody($response)),
        );
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function inactiveRangeValues(): iterable
    {
        foreach ([false, true] as $negate) {
            $suffix = $negate ? ' negated' : ' positive';
            yield 'scalar' . $suffix => ['1', $negate];
            yield 'empty map' . $suffix => [[], $negate];
            yield 'unknown operator' . $suffix => [['unknown' => '1'], $negate];
        }
    }

    public function testRelationRangeStillAppliesRecognizedOperators(): void
    {
        $this->registerArticles('active-range', ['categories.uid' => RangeFilter::class]);

        $response = $this->executeApiRequest('/_api/active-range', ['filters' => ['categories.uid' => ['gte' => '3']]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['Second Article'], $this->titles($this->decodeResponseBody($response)));
    }

    public function testRelationPathFilterWithListMatchesAnyValue(): void
    {
        $this->registerArticles('multi-path', ['categories.title' => ExactFilter::class]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/multi-path', ['filters' => ['categories.title' => ['PHP', 'API']]]),
        );

        self::assertSame(['First Article', 'Second Article'], $this->titles($body));
    }

    public function testNegatedRelationPathFilterExcludesMatchingHolders(): void
    {
        $this->registerArticles('not-path', ['categories.title' => [ExactFilter::class, ['negate' => true]]]);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/not-path', ['filters' => ['categories.title' => ['PHP', 'API']]]),
        );

        // Article 1 keeps a second category (TYPO3) — negation must still exclude it.
        self::assertSame(['Third Article'], $this->titles($body));
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function registerArticles(string $name, array $filters): void
    {
        $this->registerResource($name, [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => $name,
                'resourceType' => 'Article',
                'operations'   => ['list'],
            ],
            'columns' => [
                'title'    => ['groups' => ['list', 'show']],
                'color_id' => ['groups' => ['list', 'show']],
            ],
            'filters' => $filters,
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function titles(array $body): array
    {
        return array_values(array_map(static fn (array $member): string => $member['title'], $body['hydra:member']));
    }
}
