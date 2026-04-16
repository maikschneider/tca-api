<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class ExactFilter implements FilterInterface
{
    public function apply(QueryBuilder $qb, string $column, array $filterConfig): void
    {
        $qb->andWhere($qb->expr()->eq(
            $column,
            $qb->createNamedParameter((string)$filterConfig['value']),
        ));
    }
}
