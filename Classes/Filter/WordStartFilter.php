<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class WordStartFilter implements FilterInterface
{
    public function apply(QueryBuilder $qb, FilterContext $context): void
    {
        $value = (string)$context->value;
        $qb->andWhere($qb->expr()->like(
            $context->column,
            $qb->createNamedParameter($qb->escapeLikeWildcards($value) . '%'),
        ));
    }
}
