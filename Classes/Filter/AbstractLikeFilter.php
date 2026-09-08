<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Shared LIKE comparison for {@see PartialFilter} and {@see WordStartFilter}:
 * a single value produces one `LIKE`, a list produces an `OR` of `LIKE`s
 * (`AND` of `NOT LIKE`s when the `negate` option is set — the negation of
 * "matches any" is "matches none").
 */
abstract class AbstractLikeFilter implements FilterInterface, MultiValueFilterInterface
{
    public function apply(QueryBuilder $qb, FilterContext $context): void
    {
        $values = ValueSet::fromContext($context);
        if ($values->isEmpty()) {
            return;
        }

        $parts = [];
        foreach ($values->values as $value) {
            $pattern = $this->pattern($qb->escapeLikeWildcards((string)$value));
            $parts[] = $values->negate
                ? $qb->expr()->notLike($context->column, $qb->createNamedParameter($pattern))
                : $qb->expr()->like($context->column, $qb->createNamedParameter($pattern));
        }

        $qb->andWhere($values->negate ? $qb->expr()->and(...$parts) : $qb->expr()->or(...$parts));
    }

    /**
     * Wraps the wildcard-escaped value into the filter's LIKE pattern.
     */
    abstract protected function pattern(string $escapedValue): string;
}
