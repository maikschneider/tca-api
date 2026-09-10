<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * `WHERE column = value`, or `WHERE column IN (…)` when the request supplies a list
 * (`?filters[color_id][]=1&filters[color_id][]=2`). With the `negate` option the
 * comparison becomes `!=` / `NOT IN`.
 */
final class ExactFilter implements FilterInterface, FilterPreResolvableInterface, MultiValueFilterInterface
{
    public function __construct(
        private readonly ColumnTypeResolver $typeResolver,
    ) {
    }

    public function apply(QueryBuilder $qb, FilterContext $context): void
    {
        $values = ValueSet::fromContext($context);
        if ($values->isEmpty()) {
            return;
        }

        // Preserve leading zeros when no explicit or TCA-derived type is available.
        $type = $this->typeResolver->resolveType($context) ?? 'string';

        if ($values->isMulti()) {
            $parameter = $this->typeResolver->namedArrayParameter($qb, $values->values, $type);
            $qb->andWhere($values->negate
                ? $qb->expr()->notIn($context->column, $parameter)
                : $qb->expr()->in($context->column, $parameter));

            return;
        }

        $parameter = $this->typeResolver->namedParameter($qb, $values->first(), $type);
        $qb->andWhere($values->negate
            ? $qb->expr()->neq($context->column, $parameter)
            : $qb->expr()->eq($context->column, $parameter));
    }

    public function preResolve(FilterDefinition $definition): FilterDefinition
    {
        return $this->typeResolver->preResolveType($definition);
    }
}
