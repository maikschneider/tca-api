<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
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
 * path filters inherit the full comparison vocabulary (exact, range, like, …). The hop
 * traversal and parameter handling live in {@see RelationSubqueryBuilder}.
 */
final class RelationPathFilter implements FilterInterface, FilterPreResolvableInterface
{
    /** @var array<class-string, FilterInterface>|null */
    private ?array $leafMap = null;

    /**
     * @param iterable<FilterInterface> $filters
     */
    public function __construct(
        private readonly RelationSubqueryBuilder $subqueryBuilder,
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
            [$hops, $leafTable, $leafColumn] = $this->subqueryBuilder->resolvePath($definition->table, $definition->column);
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
            [$hops, $leafTable, $leafColumn] = $this->subqueryBuilder->resolvePath($context->table, $context->column);
        }

        /** @var list<RelationHop> $hops */

        // The declared leaf filter builds the WHERE on the deepest table; the builder folds
        // the hops back to the resource table and namespaces the leaf parameters onto $qb.
        $prefix     = 'relpath_' . substr(md5($context->column), 0, 8) . '_';
        $currentSet = $this->subqueryBuilder->buildUidSubquery(
            $qb,
            $hops,
            $leafTable,
            $prefix,
            function (QueryBuilder $leafQb, string $leafAlias) use ($context, $leafTable, $leafColumn): void {
                $this->leafFilter($context)->apply($leafQb, new FilterContext(
                    value:          $context->value,
                    table:          $leafTable,
                    column:         $leafColumn,
                    options:        $this->leafOptions($context),
                    request:        $context->request,
                    resourceConfig: $context->resourceConfig,
                ));
            },
        );

        $qb->andWhere($qb->expr()->in('t.uid', '(' . $currentSet . ')'));
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
