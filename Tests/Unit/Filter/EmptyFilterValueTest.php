<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use MaikSchneider\TcaApi\Filter\ColumnTypeResolver;
use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\FilterContext;
use MaikSchneider\TcaApi\Filter\MmFilter;
use MaikSchneider\TcaApi\Filter\PartialFilter;
use MaikSchneider\TcaApi\Filter\WordStartFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * A filter value that normalises to no values at all (an empty list, or a value
 * that is neither scalar nor array) must add no constraint — the collection stays
 * unfiltered rather than matching nothing.
 */
final class EmptyFilterValueTest extends TestCase
{
    /** @var QueryBuilder&\PHPUnit\Framework\MockObject\MockObject */
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $this->qb = $this->createMock(QueryBuilder::class);
        $this->qb->expects(self::never())->method('andWhere');
    }

    #[Test]
    public function exactFilterIgnoresAnEmptyList(): void
    {
        $filter = new ExactFilter(new ColumnTypeResolver($this->createMock(TcaSchemaFactory::class)));

        $filter->apply($this->qb, $this->context([]));
    }

    #[Test]
    public function exactFilterIgnoresANullValue(): void
    {
        $filter = new ExactFilter(new ColumnTypeResolver($this->createMock(TcaSchemaFactory::class)));

        $filter->apply($this->qb, $this->context(null));
    }

    #[Test]
    public function partialFilterIgnoresAnEmptyList(): void
    {
        (new PartialFilter())->apply($this->qb, $this->context([]));
    }

    #[Test]
    public function wordStartFilterIgnoresAnEmptyList(): void
    {
        (new WordStartFilter())->apply($this->qb, $this->context([]));
    }

    #[Test]
    public function mmFilterIgnoresAnEmptyList(): void
    {
        $filter = new MmFilter($this->createMock(TcaSchemaFactory::class));

        // mm_table set so the TCA derivation is skipped — the empty value is the subject here.
        $filter->apply($this->qb, $this->context([], [
            'mm_table'       => 'tx_test_mm',
            'mm_local_key'   => 'uid_foreign',
            'mm_foreign_key' => 'uid_local',
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function context(mixed $value, array $options = []): FilterContext
    {
        return new FilterContext(value: $value, table: '', column: 'color_id', options: $options);
    }
}
