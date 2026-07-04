<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Filter;

use MaikSchneider\TcaApi\Filter\FilterContext;
use MaikSchneider\TcaApi\Filter\FilterDefinition;
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

    /** @var array<string, mixed> */
    private array $tcaBackup = [];

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

        $this->tcaBackup = $GLOBALS['TCA'] ?? [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tcaBackup;
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

    // ── columns() guard ─────────────────────────────────────────────────────────

    #[Test]
    public function nonArrayColumnsOptionIsNoOp(): void
    {
        $this->qb->expects(self::never())->method('andWhere');

        $filter = new SearchFilter($this->subqueryBuilder);
        $filter->apply($this->qb, new FilterContext(
            value: 'hello',
            table: '',
            column: 'q',
            options: ['columns' => 'not-an-array'],
        ));
    }

    // ── __pathError rethrow ─────────────────────────────────────────────────────

    #[Test]
    public function applyThrowsWhenPathErrorRecorded(): void
    {
        $filter = new SearchFilter($this->subqueryBuilder);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Relation path: "tx_test.bad" is not a known TCA column.');

        $filter->apply($this->qb, new FilterContext(
            value: 'hello',
            table: 'tx_test',
            column: 'q',
            options: [
                'columns'     => ['title', 'bad.title'],
                '__pathError' => 'Relation path: "tx_test.bad" is not a known TCA column.',
            ],
        ));
    }

    // ── preResolve ──────────────────────────────────────────────────────────────

    #[Test]
    public function preResolveWithPlainColumnsOnlyReturnsDefinitionUnchanged(): void
    {
        $definition = new FilterDefinition(SearchFilter::class, 'tx_test', 'q', ['columns' => ['title', 'body']]);

        $result = (new SearchFilter($this->subqueryBuilder))->preResolve($definition);

        self::assertNull($result->option('__searchPaths'));
        self::assertNull($result->option('__pathError'));
    }

    #[Test]
    public function preResolveWithoutTcaContextReturnsDefinitionUnchanged(): void
    {
        // Dotted column, but the resource table has no TCA → deferred to apply() (lazy).
        $definition = new FilterDefinition(SearchFilter::class, 'tx_absent_table', 'q', ['columns' => ['categories.title']]);

        $result = (new SearchFilter($this->subqueryBuilder))->preResolve($definition);

        self::assertNull($result->option('__searchPaths'));
        self::assertNull($result->option('__pathError'));
    }

    #[Test]
    public function preResolveResolvesDottedColumnsIntoSearchPaths(): void
    {
        $GLOBALS['TCA']['tx_test']['columns']['color_id']['config'] = [
            'type'          => 'select',
            'foreign_table' => 'tx_color',
        ];
        $GLOBALS['TCA']['tx_color']['columns']['name']['config'] = ['type' => 'input'];

        $definition = new FilterDefinition(SearchFilter::class, 'tx_test', 'q', ['columns' => ['title', 'color_id.name']]);

        $result = (new SearchFilter($this->subqueryBuilder))->preResolve($definition);

        self::assertNull($result->option('__pathError'));
        $paths = $result->option('__searchPaths');
        self::assertIsArray($paths);
        self::assertArrayHasKey('color_id.name', $paths);
        self::assertSame('tx_color', $paths['color_id.name']['leafTable']);
        self::assertSame('name', $paths['color_id.name']['leafColumn']);
    }

    #[Test]
    public function preResolveRecordsPathErrorForUnknownColumn(): void
    {
        $GLOBALS['TCA']['tx_test']['columns']['color_id']['config'] = [
            'type'          => 'select',
            'foreign_table' => 'tx_color',
        ];
        $GLOBALS['TCA']['tx_color']['columns']['name']['config'] = ['type' => 'input'];

        $definition = new FilterDefinition(SearchFilter::class, 'tx_test', 'q', ['columns' => ['color_id.namee']]);

        $result = (new SearchFilter($this->subqueryBuilder))->preResolve($definition);

        self::assertNull($result->option('__searchPaths'));
        self::assertStringContainsString('tx_color.namee', (string)$result->option('__pathError'));
    }
}
