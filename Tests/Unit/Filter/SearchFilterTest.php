<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use MaikSchneider\TcaApi\Filter\FilterContext;
use MaikSchneider\TcaApi\Filter\RelationResolver;
use MaikSchneider\TcaApi\Filter\RelationSubqueryBuilder;
use MaikSchneider\TcaApi\Filter\SearchFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class SearchFilterTest extends TestCase
{
    /** @var QueryBuilder&\PHPUnit\Framework\MockObject\MockObject */
    private QueryBuilder $qb;

    /** @var ExpressionBuilder&\PHPUnit\Framework\MockObject\MockObject */
    private ExpressionBuilder $expr;

    private RelationSubqueryBuilder $subqueryBuilder;

    protected function setUp(): void
    {
        $this->expr = $this->createMock(ExpressionBuilder::class);

        $this->qb = $this->createMock(QueryBuilder::class);
        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('escapeLikeWildcards')->willReturnArgument(0);
        $this->qb->method('createNamedParameter')->willReturnArgument(0);

        // Plain-column tests never touch the builder; the ConnectionPool mock is unused.
        $this->subqueryBuilder = new RelationSubqueryBuilder(
            $this->createMock(ConnectionPool::class),
            new RelationResolver(),
        );
    }

    // ── Early return guard ────────────────────────────────────────────────────

    #[Test]
    public function emptyColumnsOptionIsNoOp(): void
    {
        $this->qb->expects(self::never())->method('andWhere');

        $filter = new SearchFilter($this->subqueryBuilder);
        $filter->apply($this->qb, new FilterContext(value: 'hello', table: '', column: 'title'));
    }

    #[Test]
    public function columnsOptionNotSetIsNoOp(): void
    {
        $this->qb->expects(self::never())->method('andWhere');

        $filter = new SearchFilter($this->subqueryBuilder);
        $filter->apply($this->qb, new FilterContext(
            value: 'hello',
            table: '',
            column: 'title',
            options: ['columns' => []],
        ));
    }

    // ── Main apply path ───────────────────────────────────────────────────────

    private function makeComposite(): CompositeExpression
    {
        return CompositeExpression::or('1=1', '1=1');
    }

    #[Test]
    public function singleColumnProducesOneOrPart(): void
    {
        $this->expr->method('like')->willReturn('title LIKE :p1');
        $this->expr->method('or')->willReturn($this->makeComposite());
        $this->qb->expects(self::once())->method('andWhere');

        $filter = new SearchFilter($this->subqueryBuilder);
        $filter->apply($this->qb, new FilterContext(
            value: 'hello',
            table: '',
            column: 'q',
            options: ['columns' => ['title']],
        ));
    }

    #[Test]
    public function multipleColumnsProduceOrCondition(): void
    {
        $this->expr->method('like')->willReturnCallback(static fn (string $col, mixed $p) => "$col LIKE $p");
        $this->expr->method('or')->willReturn($this->makeComposite());
        $this->qb->expects(self::once())->method('andWhere');

        $filter = new SearchFilter($this->subqueryBuilder);
        $filter->apply($this->qb, new FilterContext(
            value: 'test',
            table: '',
            column: 'q',
            options: ['columns' => ['title', 'body']],
        ));
    }

    // ── match option ──────────────────────────────────────────────────────────

    #[Test]
    public function wordStartMatchUsesTrailingWildcardOnly(): void
    {
        $patterns = [];
        $this->expr->method('like')->willReturnCallback(
            static function (string $col, mixed $p) use (&$patterns): string {
                $patterns[] = $p;
                return "$col LIKE $p";
            }
        );
        $this->expr->method('or')->willReturn($this->makeComposite());
        $this->qb->method('createNamedParameter')->willReturnArgument(0);

        $filter = new SearchFilter($this->subqueryBuilder);
        $filter->apply($this->qb, new FilterContext(
            value: 'hello',
            table: '',
            column: 'q',
            options: ['columns' => ['title'], 'match' => 'word_start'],
        ));

        self::assertCount(1, $patterns);
        self::assertStringEndsWith('%', $patterns[0]);
        self::assertStringStartsWith('hello', $patterns[0]);
    }

    #[Test]
    public function defaultMatchUsesPartialWildcard(): void
    {
        $patterns = [];
        $this->expr->method('like')->willReturnCallback(
            static function (string $col, mixed $p) use (&$patterns): string {
                $patterns[] = $p;
                return "$col LIKE $p";
            }
        );
        $this->expr->method('or')->willReturn($this->makeComposite());
        $this->qb->method('createNamedParameter')->willReturnArgument(0);

        $filter = new SearchFilter($this->subqueryBuilder);
        $filter->apply($this->qb, new FilterContext(
            value: 'hello',
            table: '',
            column: 'q',
            options: ['columns' => ['title']],
        ));

        self::assertCount(1, $patterns);
        self::assertStringStartsWith('%', $patterns[0]);
        self::assertStringEndsWith('%', $patterns[0]);
    }
}
