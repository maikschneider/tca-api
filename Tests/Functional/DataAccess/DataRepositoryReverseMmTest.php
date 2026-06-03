<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\DataAccess;

use MaikSchneider\TcaApi\DataAccess\DataRepository;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for {@see DataRepository::findReverseMmRelations}.
 *
 * Covers ISCs 7-12 of the reverse-MM wildcard fix:
 *
 *   ISC-7  — Method exists with correct signature.
 *   ISC-8  — SELECT projects all five MM columns
 *            (uid_local, uid_foreign, tablenames, fieldname, sorting_foreign).
 *   ISC-9  — WHERE clause is a disjunction of (tablenames, fieldname) pairs
 *            derived from $oppositeUsage; rows for other tables/fields filtered out.
 *   ISC-10 — Rows ordered by (uid_local, sorting_foreign) per parent.
 *   ISC-11 — Return shape is [parentUid => [['table'=>..,'uid'=>..], ...]] —
 *            identical to multiTableRelations[column][parentUid].
 *   ISC-12 — Empty inputs short-circuit (empty $parentUids and empty $oppositeUsage).
 *
 * Plus edge cases:
 *   - $extraConstraints applies as additional `col = val` clauses on the MM table.
 *   - Rows whose tablenames is outside $oppositeUsage are excluded.
 *   - Parents with no MM rows still appear in the result map (empty list).
 */
final class DataRepositoryReverseMmTest extends ApiFunctionalTestCase
{
    private const MM_TABLE = 'sys_category_record_mm';

    private DataRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_category_record_mm_mixed.csv');

        $this->repository = $this->get(DataRepository::class);
    }

    // ── ISC-7: method signature ───────────────────────────────────────────────

    #[Test]
    public function methodExistsWithExpectedSignature(): void
    {
        $reflection = new \ReflectionMethod($this->repository, 'findReverseMmRelations');

        self::assertTrue($reflection->isPublic(), 'findReverseMmRelations must be public');
        self::assertSame('array', $reflection->getReturnType()?->__toString());

        $params = $reflection->getParameters();
        self::assertCount(4, $params, 'findReverseMmRelations expects 4 parameters');

        self::assertSame('parentUids', $params[0]->getName());
        self::assertSame('array', $params[0]->getType()?->__toString());

        self::assertSame('mmTable', $params[1]->getName());
        self::assertSame('string', $params[1]->getType()?->__toString());

        self::assertSame('oppositeUsage', $params[2]->getName());
        self::assertSame('array', $params[2]->getType()?->__toString());

        self::assertSame('extraConstraints', $params[3]->getName());
        self::assertSame('array', $params[3]->getType()?->__toString());
        self::assertTrue($params[3]->isDefaultValueAvailable(), 'extraConstraints must be optional');
        self::assertSame([], $params[3]->getDefaultValue());
    }

    // ── ISC-12: empty-input short circuits ─────────────────────────────────────

    #[Test]
    public function emptyParentUidsReturnsEmptyArray(): void
    {
        $result = $this->repository->findReverseMmRelations(
            parentUids: [],
            mmTable: self::MM_TABLE,
            oppositeUsage: ['tx_myext_domain_model_article' => ['categories']],
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function emptyOppositeUsageReturnsEmptyArray(): void
    {
        $result = $this->repository->findReverseMmRelations(
            parentUids: [1, 2, 3],
            mmTable: self::MM_TABLE,
            oppositeUsage: [],
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function oppositeUsageWithOnlyEmptyFieldListsReturnsEmptyArray(): void
    {
        $result = $this->repository->findReverseMmRelations(
            parentUids: [1, 2, 3],
            mmTable: self::MM_TABLE,
            oppositeUsage: ['tx_myext_domain_model_article' => [], 'pages' => []],
        );

        self::assertSame([], $result);
    }

    // ── ISC-11: return shape ──────────────────────────────────────────────────

    #[Test]
    public function returnShapeMatchesMultiTableRelations(): void
    {
        $result = $this->repository->findReverseMmRelations(
            parentUids: [1],
            mmTable: self::MM_TABLE,
            oppositeUsage: [
                'tx_myext_domain_model_article' => ['categories'],
                'pages'                         => ['categories'],
            ],
        );

        self::assertArrayHasKey(1, $result, 'Parent UID 1 must appear in the result');
        self::assertNotEmpty($result[1], 'Parent UID 1 must have at least one relation');

        foreach ($result[1] as $entry) {
            // Shape `{table: string, uid: int}` is enforced by the PHPDoc return type;
            // runtime checks ensure the producer actually populates both keys.
            self::assertArrayHasKey('table', $entry);
            self::assertArrayHasKey('uid', $entry);
            self::assertCount(2, $entry, 'Entry must contain only `table` and `uid` keys');
            self::assertNotSame('', $entry['table'], 'table must be a non-empty string');
            self::assertGreaterThan(0, $entry['uid'], 'uid must be positive');
        }
    }

    #[Test]
    public function parentWithoutRelationsAppearsAsEmptyList(): void
    {
        $result = $this->repository->findReverseMmRelations(
            parentUids: [1, 99],   // 99 has no MM rows
            mmTable: self::MM_TABLE,
            oppositeUsage: ['tx_myext_domain_model_article' => ['categories']],
        );

        self::assertArrayHasKey(99, $result);
        self::assertSame([], $result[99]);
    }

    // ── ISC-10: ordering by (uid_local, sorting_foreign) ─────────────────────

    #[Test]
    public function rowsOrderedBySortingForeignWithinParent(): void
    {
        // Fixture rows for parent uid_local=1 (single forward table to make ordering deterministic):
        //   uid_foreign=1, sorting_foreign=2
        //   uid_foreign=2, sorting_foreign=1
        // Expected order: uid=2 (sorting_foreign=1), then uid=1 (sorting_foreign=2).
        $result = $this->repository->findReverseMmRelations(
            parentUids: [1],
            mmTable: self::MM_TABLE,
            oppositeUsage: ['tx_myext_domain_model_article' => ['categories']],
        );

        self::assertCount(2, $result[1]);
        self::assertSame(2, $result[1][0]['uid'], 'sorting_foreign=1 must come first');
        self::assertSame(1, $result[1][1]['uid'], 'sorting_foreign=2 must come second');
    }

    #[Test]
    public function resultsGroupedByParentUidLocal(): void
    {
        $result = $this->repository->findReverseMmRelations(
            parentUids: [1, 2, 3],
            mmTable: self::MM_TABLE,
            oppositeUsage: [
                'tx_myext_domain_model_article' => ['categories'],
                'pages'                         => ['categories'],
            ],
        );

        self::assertArrayHasKey(1, $result);
        self::assertArrayHasKey(2, $result);
        self::assertArrayHasKey(3, $result);

        // Parent 1: 2 article rows + 1 pages row = 3 entries
        self::assertCount(3, $result[1]);

        // Parent 2: 1 article row (article fieldname=categories), 0 pages rows
        //   (the pages fieldname=other_categories row is filtered out below).
        self::assertCount(1, $result[2]);
        self::assertSame('tx_myext_domain_model_article', $result[2][0]['table']);
        self::assertSame(3, $result[2][0]['uid']);

        // Parent 3: 1 pages row + 0 tt_content rows (tt_content not in oppositeUsage)
        self::assertCount(1, $result[3]);
        self::assertSame('pages', $result[3][0]['table']);
        self::assertSame(2, $result[3][0]['uid']);
    }

    // ── ISC-9: WHERE disjunction filters by (tablenames, fieldname) pairs ─────

    #[Test]
    public function fieldnameFilteringExcludesUnlistedFields(): void
    {
        // Fixture for parent 2:
        //   tx_myext_domain_model_article / categories / uid_foreign=3
        //   pages / other_categories / uid_foreign=1   <-- fieldname NOT in oppositeUsage
        $result = $this->repository->findReverseMmRelations(
            parentUids: [2],
            mmTable: self::MM_TABLE,
            oppositeUsage: [
                'tx_myext_domain_model_article' => ['categories'],
                'pages'                         => ['categories'],  // not 'other_categories'
            ],
        );

        self::assertCount(1, $result[2]);
        self::assertSame('tx_myext_domain_model_article', $result[2][0]['table']);
    }

    #[Test]
    public function tablenamesFilteringExcludesUnlistedTables(): void
    {
        // Fixture for parent 3:
        //   pages / categories / uid_foreign=2
        //   tt_content / categories / uid_foreign=1   <-- table NOT in oppositeUsage
        $result = $this->repository->findReverseMmRelations(
            parentUids: [3],
            mmTable: self::MM_TABLE,
            oppositeUsage: [
                'tx_myext_domain_model_article' => ['categories'],
                'pages'                         => ['categories'],
            ],
        );

        self::assertCount(1, $result[3]);
        self::assertSame('pages', $result[3][0]['table']);
        self::assertSame(2, $result[3][0]['uid']);
    }

    #[Test]
    public function multipleFieldnamesPerTableAllMatched(): void
    {
        // Parent 2 has one pages row with fieldname=other_categories.
        // When oppositeUsage allows both fieldnames for pages, that row must appear.
        $result = $this->repository->findReverseMmRelations(
            parentUids: [2],
            mmTable: self::MM_TABLE,
            oppositeUsage: [
                'tx_myext_domain_model_article' => ['categories'],
                'pages'                         => ['categories', 'other_categories'],
            ],
        );

        self::assertCount(2, $result[2]);

        // sorting_foreign for parent=2: article (sf=1), pages other_categories (sf=2)
        self::assertSame('tx_myext_domain_model_article', $result[2][0]['table']);
        self::assertSame('pages', $result[2][1]['table']);
    }

    // ── ISC-8: projects all five MM columns (indirect — via behavior) ────────
    //
    // We cannot inspect the raw SELECT clause without intercepting the QueryBuilder,
    // but ISC-8 is functionally observable: the result correctly maps `tablenames`
    // and `uid_foreign` (proving they are selected) and ordering by sorting_foreign
    // works (proving sorting_foreign is selected). uid_local is selected because
    // grouping by parent works. `fieldname` is selected because the WHERE-disjunct
    // discrimination would not work otherwise.

    #[Test]
    public function selectProjectsTablenamesAndUidForeign(): void
    {
        $result = $this->repository->findReverseMmRelations(
            parentUids: [1],
            mmTable: self::MM_TABLE,
            oppositeUsage: ['pages' => ['categories']],
        );

        self::assertCount(1, $result[1]);
        self::assertSame('pages', $result[1][0]['table']);
        self::assertSame(1, $result[1][0]['uid']);
    }

    // ── extraConstraints ──────────────────────────────────────────────────────

    #[Test]
    public function extraConstraintsAppliedAsEqualityFilters(): void
    {
        // Parent 1 has three rows; constrain to uid_foreign=2 only.
        $result = $this->repository->findReverseMmRelations(
            parentUids: [1, 2, 3],
            mmTable: self::MM_TABLE,
            oppositeUsage: [
                'tx_myext_domain_model_article' => ['categories'],
                'pages'                         => ['categories', 'other_categories'],
            ],
            extraConstraints: ['uid_foreign' => 2],
        );

        // Parent 1: article uid_foreign=2 (sf=1) survives → 1 entry.
        self::assertCount(1, $result[1]);
        self::assertSame(2, $result[1][0]['uid']);
        self::assertSame('tx_myext_domain_model_article', $result[1][0]['table']);

        // Parent 2: article uid_foreign=3 fails, pages other_categories uid_foreign=1 fails → empty.
        self::assertSame([], $result[2]);

        // Parent 3: pages categories uid_foreign=2 survives, tt_content excluded by tablenames disjunct.
        self::assertCount(1, $result[3]);
        self::assertSame('pages', $result[3][0]['table']);
        self::assertSame(2, $result[3][0]['uid']);
    }
}
