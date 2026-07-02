<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use MaikSchneider\TcaApi\Filter\ExactFilter;
use MaikSchneider\TcaApi\Filter\FilterContext;
use MaikSchneider\TcaApi\Filter\RelationPathFilter;
use MaikSchneider\TcaApi\Filter\RelationResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Unit tests for RelationPathFilter's runtime failure modes.
 *
 * Path resolution and the guards around it run before any DB access, so most cases work
 * with plain mocks; the leaf-filter guard needs a fluent QueryBuilder stub to be reached.
 */
final class RelationPathFilterTest extends TestCase
{
    #[Test]
    public function applyRejectsPathExceedingMaxRelationHops(): void
    {
        $filter = new RelationPathFilter(
            $this->createMock(ConnectionPool::class),
            new RelationResolver(),
        );

        // 4 relation hops (+ leaf column) exceeds the cap of 3.
        $context = new FilterContext(
            value:   'x',
            table:   'tx_myext_domain_model_article',
            column:  'parent_id.parent_id.parent_id.parent_id.title',
            options: ['__leafFilter' => ExactFilter::class],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the maximum of 3 relation hops');

        $filter->apply($this->createMock(QueryBuilder::class), $context);
    }

    #[Test]
    public function applyRejectsMalformedPath(): void
    {
        $filter = new RelationPathFilter(
            $this->createMock(ConnectionPool::class),
            new RelationResolver(),
        );

        // Trailing dot → empty leaf column.
        $context = new FilterContext(value: 'x', table: 't', column: 'categories.');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be of the form "relation.….column"');

        $filter->apply($this->createMock(QueryBuilder::class), $context);
    }

    #[Test]
    public function applyRethrowsDeferredPathError(): void
    {
        $filter = new RelationPathFilter(
            $this->createMock(ConnectionPool::class),
            new RelationResolver(),
        );

        // A path error recorded at boot (preResolve) surfaces here at request time.
        $context = new FilterContext(
            value:   'x',
            table:   't',
            column:  'bad.title',
            options: ['__pathError' => 'Relation path: "t.bad" is not a known TCA column.'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"t.bad" is not a known TCA column');

        $filter->apply($this->createMock(QueryBuilder::class), $context);
    }

    #[Test]
    public function applyThrowsWhenLeafFilterIsNotRegistered(): void
    {
        $leafQb = $this->createMock(QueryBuilder::class);
        $leafQb->method('select')->willReturnSelf();
        $leafQb->method('from')->willReturnSelf();

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturn($leafQb);

        // Empty filter iterable → no leaf filter is registered.
        $filter = new RelationPathFilter($pool, new RelationResolver(), []);

        $context = new FilterContext(
            value:   'x',
            table:   't',
            column:  'rel.title',
            options: [
                '__hops'       => [],            // pre-resolved → skip resolvePath
                '__leafTable'  => 'leaf_table',
                '__leafColumn' => 'title',
                '__leafFilter' => 'Vendor\\Does\\NotExist',
            ],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a registered filter');

        $filter->apply($this->createMock(QueryBuilder::class), $context);
    }
}
