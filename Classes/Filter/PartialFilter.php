<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class PartialFilter implements FilterInterface
{
    public function getStrategy(): string
    {
        return 'partial';
    }

    public function apply(QueryBuilder $qb, string $column, array $filterConfig): void
    {
        $value = (string)$filterConfig['value'];
        $qb->andWhere($qb->expr()->like(
            $column,
            $qb->createNamedParameter('%' . $qb->escapeLikeWildcards($value) . '%'),
        ));
    }
}
