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
 * Unit tests for RelationPathFilter.
 *
 * The depth cap is enforced in resolvePath() before any TCA lookup or query building,
 * so it can be exercised with plain mocks.
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
}
