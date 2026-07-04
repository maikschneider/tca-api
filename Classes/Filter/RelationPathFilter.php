<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Filters the resource by a property reached through one or more relation hops,
 * expressed as a dotted path on the filter key (see {@see FilterDefinition::fromRaw()}
 * for how a dotted key is auto-routed here).
 *
 *   'color_id.name'            → colour's name          (one FK hop)
 *   'categories.title'         → a category's title     (one MM hop)
 *   'categories.parent.title'  → a category's parent's title (MM + FK hops)
 *
 * The last path segment is a scalar column on the deepest related table; the comparison
 * against it is delegated to the *declared* leaf filter (`ExactFilter` by default), so
 * path filters inherit the full comparison vocabulary (exact, range, like, …).
 *
 * SQL shape: the value comparison runs against the deepest table, and each relation hop
 * wraps the previous result in an `IN (subquery)` that maps related UIDs back to the
 * holder record — built inside-out and de-duplicating, so pagination stays correct.
 * All value parameters are namespaced onto the outer query builder to avoid `:dcValue*`
 * collisions with the main query.
 */
final class RelationPathFilter implements FilterInterface, FilterPreResolvableInterface
{
    /**
     * Hard cap on the number of relation hops in a single path (the leaf column is not
     * a hop). Bounds the depth of nested subqueries {@see resolvePath()} builds, so a
     * misconfigured path cannot generate an unbounded query.
     */
    private const MAX_RELATION_HOPS = 3;

    /** @var array<class-string, FilterInterface>|null */
    private ?array $leafMap = null;

    /**
     * @param iterable<FilterInterface> $filters
     */
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RelationResolver $resolver,
        #[TaggedIterator('tca_api.filter')]
        private readonly iterable $filters = [],
    ) {
    }

    public function preResolve(FilterDefinition $definition): FilterDefinition
    {
        if (!str_contains($definition->column, '.')) {
            return $definition;
        }

        // No compiled TCA context (unit tests) — apply() resolves lazily instead.
        if ($definition->table === '' || !isset($GLOBALS['TCA'][$definition->table])) {
            return $definition;
        }

        try {
            [$hops, $leafTable, $leafColumn] = $this->resolvePath($definition->table, $definition->column);
        } catch (\InvalidArgumentException $e) {
            // Record the failure for boot-time validation
            // (ApiDefinitionLoader will surface __pathError as an InvalidApiDefinitionException).
            return $definition->withOptions(['__pathError' => $e->getMessage()]);
        }

        return $definition->withOptions([
            '__hops'       => $hops,
            '__leafTable'  => $leafTable,
            '__leafColumn' => $leafColumn,
        ]);
    }

    public function apply(QueryBuilder $qb, FilterContext $context): void
    {
        $error = $context->option('__pathError');
        if (\is_string($error)) {
            throw new \InvalidArgumentException($error);
        }

        $hops       = $context->option('__hops');
        $leafTable  = $context->option('__leafTable');
        $leafColumn = $context->option('__leafColumn');

        if (!\is_array($hops) || !\is_string($leafTable) || !\is_string($leafColumn)) {
            [$hops, $leafTable, $leafColumn] = $this->resolvePath($context->table, $context->column);
        }

        // 1. Leaf comparison — the declared filter builds the WHERE on the deepest table.
        $leafQb = $this->connectionPool->getQueryBuilderForTable($leafTable);
        $leafQb->select('leaf.uid')->from($leafTable, 'leaf');
        $this->leafFilter($context)->apply($leafQb, new FilterContext(
            value:          $context->value,
            table:          $leafTable,
            column:         $leafColumn,
            options:        $this->leafOptions($context),
            request:        $context->request,
            resourceConfig: $context->resourceConfig,
        ));

        // Namespace the leaf filter's parameters onto the outer builder so they never
        // collide with the main query's own :dcValue* placeholders.
        $prefix     = 'relpath_' . substr(md5($context->column), 0, 8) . '_';
        $currentSet = $this->rebindParameters($leafQb, $qb, $prefix);

        // 2. Fold the relation hops from the deepest back to the resource table.
        for ($i = \count($hops) - 1; $i >= 0; $i--) {
            $currentSet = $this->wrapHop($qb, $hops[$i], $currentSet, $i);
        }

        $qb->andWhere($qb->expr()->in('t.uid', '(' . $currentSet . ')'));
    }

    /**
     * @return array{0: list<RelationHop>, 1: string, 2: string}
     */
    private function resolvePath(string $table, string $path): array
    {
        $segments   = explode('.', $path);
        $leafColumn = (string)array_pop($segments);
        if ($segments === [] || $leafColumn === '') {
            throw new \InvalidArgumentException(
                sprintf('Relation path "%s" must be of the form "relation.….column".', $path),
            );
        }

        if (\count($segments) > self::MAX_RELATION_HOPS) {
            throw new \InvalidArgumentException(sprintf(
                'Relation path "%s" exceeds the maximum of %d relation hops.',
                $path,
                self::MAX_RELATION_HOPS,
            ));
        }

        $hops    = [];
        $current = $table;
        foreach ($segments as $segment) {
            $hop     = $this->resolver->resolve($current, $segment);
            $hops[]  = $hop;
            $current = $hop->targetTable;
        }

        // The leaf column is compared in SQL against the resolved leaf table, so a typo
        // there would otherwise only fail at runtime. Reject it here (i.e. at boot) —
        // guarded so a leaf table without TCA columns is not falsely rejected, and the
        // universal system columns uid/pid (absent from TCA `columns`) are allowed.
        $leafColumns = $GLOBALS['TCA'][$current]['columns'] ?? null;
        if (\is_array($leafColumns)
            && $leafColumn !== 'uid'
            && $leafColumn !== 'pid'
            && !isset($leafColumns[$leafColumn])
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Relation path: leaf column "%s.%s" is not a known TCA column.',
                $current,
                $leafColumn,
            ));
        }

        return [$hops, $current, $leafColumn];
    }

    private function wrapHop(QueryBuilder $qb, RelationHop $hop, string $innerSet, int $level): string
    {
        $srcAlias = 'src' . $level;
        $sub      = $this->connectionPool->getQueryBuilderForTable($hop->sourceTable);
        $sub->select($srcAlias . '.uid')->from($hop->sourceTable, $srcAlias);

        if ($hop->kind === RelationHop::KIND_MM) {
            // Source rows linked through the MM table to a UID in the inner set.
            $mmAlias = 'mm' . $level;
            $sub->join(
                $srcAlias,
                $hop->mmTable,
                $mmAlias,
                $sub->expr()->eq($mmAlias . '.' . $hop->mmSourceKey, $sub->quoteIdentifier($srcAlias . '.uid')),
            )->where($sub->expr()->in($mmAlias . '.' . $hop->mmTargetKey, '(' . $innerSet . ')'));

            foreach ($hop->mmMatch as $col => $val) {
                $sub->andWhere($sub->expr()->eq($mmAlias . '.' . $col, $sub->quote((string)$val)));
            }

            return $sub->getSQL();
        }

        if ($hop->kind === RelationHop::KIND_INLINE) {
            // Source rows whose inline child (back-pointing via foreignField) is in the
            // inner set. Joining the child table restricts it too; the parent stays
            // restricted through the source table this query is built on.
            $childAlias = 'inl' . $level;
            $sub->join(
                $srcAlias,
                $hop->targetTable,
                $childAlias,
                $sub->expr()->eq($childAlias . '.' . $hop->foreignField, $sub->quoteIdentifier($srcAlias . '.uid')),
            )->where($sub->expr()->in($childAlias . '.uid', '(' . $innerSet . ')'));

            if ($hop->foreignTableField !== null) {
                $sub->andWhere($sub->expr()->eq(
                    $childAlias . '.' . $hop->foreignTableField,
                    $sub->quote($hop->sourceTable),
                ));
            }
            foreach ($hop->foreignMatchFields as $col => $val) {
                $sub->andWhere($sub->expr()->eq($childAlias . '.' . $col, $sub->quote((string)$val)));
            }

            return $sub->getSQL();
        }

        // FK hop: source rows whose fkColumn points into the inner set.
        $sub->where($sub->expr()->in($srcAlias . '.' . $hop->fkColumn, '(' . $innerSet . ')'));

        return $sub->getSQL();
    }

    /**
     * Lifts every parameter bound on $from onto $to under a unique prefix, and rewrites
     * the corresponding placeholders in $from's SQL. Returns the rewritten SQL.
     */
    private function rebindParameters(QueryBuilder $from, QueryBuilder $to, string $prefix): string
    {
        $sql    = $from->getSQL();
        $params = $from->getParameters();
        if ($params === []) {
            return $sql;
        }

        $types = $from->getParameterTypes();

        $sql = preg_replace_callback(
            '/:(\w+)/',
            static fn (array $m): string => \array_key_exists($m[1], $params) ? ':' . $prefix . $m[1] : $m[0],
            $sql,
        ) ?? $sql;

        foreach ($params as $name => $value) {
            $to->setParameter($prefix . $name, $value, $types[$name] ?? ParameterType::STRING);
        }

        return $sql;
    }

    private function leafFilter(FilterContext $context): FilterInterface
    {
        $class = $context->option('__leafFilter');
        $class = \is_string($class) && $class !== '' ? $class : ExactFilter::class;

        return $this->leafFilterMap()[$class]
            ?? throw new \InvalidArgumentException(
                sprintf('Relation path leaf filter "%s" is not a registered filter.', $class),
            );
    }

    /**
     * @return array<class-string, FilterInterface>
     */
    private function leafFilterMap(): array
    {
        if ($this->leafMap === null) {
            $this->leafMap = [];
            foreach ($this->filters as $filter) {
                if (!$filter instanceof self) {
                    $this->leafMap[$filter::class] = $filter;
                }
            }
        }

        return $this->leafMap;
    }

    /**
     * @return array<string, mixed>
     */
    private function leafOptions(FilterContext $context): array
    {
        $options = $context->options;
        unset(
            $options['__hops'],
            $options['__leafTable'],
            $options['__leafColumn'],
            $options['__leafFilter'],
            $options['__pathError'],
        );

        return $options;
    }
}
