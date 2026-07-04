<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * OR-searches a value (LIKE) across a list of `columns`. Each column may be either:
 *
 *  - a column on the resource's own table (`title`), matched directly on alias `t`; or
 *  - a relation path (`categories.title`, `color_id.name`), matched on the related record
 *    and mapped back via a `t.uid IN (subquery)` built by {@see RelationSubqueryBuilder}.
 *
 * Dotted columns are resolved and validated at boot (preResolve); an invalid relation path
 * is surfaced as an InvalidApiDefinitionException by the loader, same as {@see RelationPathFilter}.
 */
final class SearchFilter implements FilterInterface, FilterPreResolvableInterface
{
    public function __construct(
        private readonly RelationSubqueryBuilder $subqueryBuilder,
    ) {
    }

    public function preResolve(FilterDefinition $definition): FilterDefinition
    {
        $dotted = array_filter(
            $this->columns($definition->options),
            static fn (string $col): bool => str_contains($col, '.'),
        );
        if ($dotted === []) {
            return $definition;
        }

        // No compiled TCA context (unit tests) — apply() resolves lazily instead.
        if ($definition->table === '' || !isset($GLOBALS['TCA'][$definition->table])) {
            return $definition;
        }

        $paths = [];
        foreach ($dotted as $col) {
            try {
                [$hops, $leafTable, $leafColumn] = $this->subqueryBuilder->resolvePath($definition->table, $col);
            } catch (\InvalidArgumentException $e) {
                return $definition->withOptions(['__pathError' => $e->getMessage()]);
            }
            $paths[$col] = ['hops' => $hops, 'leafTable' => $leafTable, 'leafColumn' => $leafColumn];
        }

        return $definition->withOptions(['__searchPaths' => $paths]);
    }

    public function apply(QueryBuilder $qb, FilterContext $context): void
    {
        $columns = $this->columns($context->options);
        if ($columns === []) {
            return;
        }

        $error = $context->option('__pathError');
        if (\is_string($error)) {
            throw new \InvalidArgumentException($error);
        }

        $value   = (string)$context->value;
        $match   = $context->option('match', 'partial');
        $escaped = $qb->escapeLikeWildcards($value);
        $pattern = $match === 'word_start' ? $escaped . '%' : '%' . $escaped . '%';

        /** @var array<string, array{hops: list<RelationHop>, leafTable: string, leafColumn: string}> $paths */
        $paths   = $context->option('__searchPaths', []);
        $orParts = [];

        foreach ($columns as $col) {
            if (!str_contains($col, '.')) {
                $orParts[] = $qb->expr()->like($col, $qb->createNamedParameter($pattern));
                continue;
            }

            $path = $paths[$col] ?? null;
            if ($path === null) {
                // preResolve did not run (unit/lazy context) — resolve on the fly.
                [$hops, $leafTable, $leafColumn] = $this->subqueryBuilder->resolvePath($context->table, $col);
            } else {
                ['hops' => $hops, 'leafTable' => $leafTable, 'leafColumn' => $leafColumn] = $path;
            }

            $prefix     = 'search_' . substr(md5($col), 0, 8) . '_';
            $currentSet = $this->subqueryBuilder->buildUidSubquery(
                $qb,
                $hops,
                $leafTable,
                $prefix,
                function (QueryBuilder $leafQb, string $leafAlias) use ($leafColumn, $pattern): void {
                    $leafQb->andWhere($leafQb->expr()->like(
                        $leafAlias . '.' . $leafColumn,
                        $leafQb->createNamedParameter($pattern),
                    ));
                },
            );
            $orParts[] = $qb->expr()->in('t.uid', '(' . $currentSet . ')');
        }

        // $columns is guaranteed non-empty above and every entry yields an OR part.
        $qb->andWhere($qb->expr()->or(...$orParts));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function columns(array $options): array
    {
        $columns = $options['columns'] ?? [];
        if (!\is_array($columns)) {
            return [];
        }

        return array_values(array_filter(
            $columns,
            static fn (mixed $col): bool => \is_string($col) && $col !== '',
        ));
    }
}
