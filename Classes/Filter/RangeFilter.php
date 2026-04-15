<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class RangeFilter implements FilterInterface
{
    public function getStrategy(): string
    {
        return 'range';
    }

    public function apply(QueryBuilder $qb, string $column, array $filterConfig): void
    {
        $operators = $filterConfig['value'];
        if (!\is_array($operators)) {
            return;
        }

        $map = [
            'gte' => fn (mixed $v) => $qb->expr()->gte($column, $qb->createNamedParameter((int)$v, ParameterType::INTEGER)),
            'lte' => fn (mixed $v) => $qb->expr()->lte($column, $qb->createNamedParameter((int)$v, ParameterType::INTEGER)),
            'gt'  => fn (mixed $v) => $qb->expr()->gt($column, $qb->createNamedParameter((int)$v, ParameterType::INTEGER)),
            'lt'  => fn (mixed $v) => $qb->expr()->lt($column, $qb->createNamedParameter((int)$v, ParameterType::INTEGER)),
        ];

        foreach ($operators as $op => $value) {
            if (isset($map[$op])) {
                $qb->andWhere(($map[$op])($value));
            }
        }
    }
}
