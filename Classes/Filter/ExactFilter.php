<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class ExactFilter implements FilterInterface
{
    public function apply(QueryBuilder $qb, FilterContext $context): void
    {
        $qb->andWhere($qb->expr()->eq(
            $context->column,
            $qb->createNamedParameter((string)$context->value),
        ));
    }
}
