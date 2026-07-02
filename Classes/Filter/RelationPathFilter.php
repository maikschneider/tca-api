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
            // Defer the failure to request time so a single bad path never breaks boot.
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

        $hops    = [];
        $current = $table;
        foreach ($segments as $segment) {
            $hop     = $this->resolver->resolve($current, $segment);
            $hops[]  = $hop;
            $current = $hop->targetTable;
        }

        return [$hops, $current, $leafColumn];
    }

    private function wrapHop(QueryBuilder $qb, RelationHop $hop, string $innerSet, int $level): string
    {
        if ($hop->kind === RelationHop::KIND_MM) {
            $alias = 'mm' . $level;
            $parts = [
                $qb->quoteIdentifier($alias . '.' . $hop->mmTargetKey) . ' IN (' . $innerSet . ')',
            ];
            foreach ($hop->mmMatch as $col => $val) {
                $parts[] = $qb->quoteIdentifier($alias . '.' . $col) . ' = ' . $qb->quote((string)$val);
            }

            return sprintf(
                'SELECT %s FROM %s %s WHERE %s',
                $qb->quoteIdentifier($alias . '.' . $hop->mmSourceKey),
                $qb->quoteIdentifier($hop->mmTable),
                $qb->quoteIdentifier($alias),
                implode(' AND ', $parts),
            );
        }

        // FK hop: source rows whose fkColumn points into the inner set. Built through a
        // real QueryBuilder so the source table's enable-field restrictions are applied.
        $alias = 'fk' . $level;
        $sub   = $this->connectionPool->getQueryBuilderForTable($hop->sourceTable);
        $sub->select($alias . '.uid')
            ->from($hop->sourceTable, $alias)
            ->where($sub->expr()->in($alias . '.' . $hop->fkColumn, '(' . $innerSet . ')'));

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
