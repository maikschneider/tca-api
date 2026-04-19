<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class WordStartFilter implements FilterInterface
{
    public function apply(QueryBuilder $qb, string $column, array $filterConfig): void
    {
        $value = (string)$filterConfig['value'];
        $qb->andWhere($qb->expr()->like(
            $column,
            $qb->createNamedParameter($qb->escapeLikeWildcards($value) . '%'),
        ));
    }
}
