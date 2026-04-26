<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class RangeFilter implements FilterInterface
{
    public function apply(QueryBuilder $qb, string $column, array $filterConfig): void
    {
        $operators = $filterConfig['value'];
        if (!\is_array($operators)) {
            return;
        }

        $type = isset($filterConfig['type']) && \is_string($filterConfig['type'])
            ? $filterConfig['type']
            : null;

        $map = [
            'gte' => fn (mixed $v) => $qb->expr()->gte($column, $this->namedParam($qb, $v, $type)),
            'lte' => fn (mixed $v) => $qb->expr()->lte($column, $this->namedParam($qb, $v, $type)),
            'gt'  => fn (mixed $v) => $qb->expr()->gt($column, $this->namedParam($qb, $v, $type)),
            'lt'  => fn (mixed $v) => $qb->expr()->lt($column, $this->namedParam($qb, $v, $type)),
        ];

        foreach ($operators as $op => $value) {
            if (isset($map[$op])) {
                $qb->andWhere(($map[$op])($value));
            }
        }
    }

    private function namedParam(QueryBuilder $qb, mixed $value, ?string $type): string
    {
        [$cast, $paramType] = $this->resolveParameter($value, $type);
        return $qb->createNamedParameter($cast, $paramType);
    }

    /**
     * @return array{0: mixed, 1: ParameterType}
     */
    private function resolveParameter(mixed $value, ?string $type): array
    {
        // Explicit type hint from filter config wins over autodetection.
        switch ($type) {
            case 'int':
            case 'integer':
                return [(int)$value, ParameterType::INTEGER];
            case 'float':
            case 'decimal':
                return [(string)(float)$value, ParameterType::STRING];
            case 'string':
            case 'date':
            case 'datetime':
                return [(string)$value, ParameterType::STRING];
        }

        if (\is_int($value)) {
            return [$value, ParameterType::INTEGER];
        }
        if (\is_float($value)) {
            return [(string)$value, ParameterType::STRING];
        }
        if (\is_string($value) && ctype_digit($value)) {
            return [(int)$value, ParameterType::INTEGER];
        }
        if (\is_numeric($value)) {
            return [(string)$value, ParameterType::STRING];
        }

        return [(string)$value, ParameterType::STRING];
    }
}
