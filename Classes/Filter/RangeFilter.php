<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class RangeFilter implements FilterInterface, FilterPreResolvableInterface
{
    private const OPERATORS = ['gte', 'lte', 'gt', 'lt'];

    public function __construct(
        private readonly ColumnTypeResolver $typeResolver,
    ) {
    }

    public function apply(QueryBuilder $qb, FilterContext $context): void
    {
        $operators = $context->value;
        if (!\is_array($operators)) {
            return;
        }

        $type = $this->typeResolver->resolveType($context);

        $map = [
            'gte' => fn (mixed $v) => $qb->expr()->gte($context->column, $this->typeResolver->namedParameter($qb, $v, $type)),
            'lte' => fn (mixed $v) => $qb->expr()->lte($context->column, $this->typeResolver->namedParameter($qb, $v, $type)),
            'gt'  => fn (mixed $v) => $qb->expr()->gt($context->column, $this->typeResolver->namedParameter($qb, $v, $type)),
            'lt'  => fn (mixed $v) => $qb->expr()->lt($context->column, $this->typeResolver->namedParameter($qb, $v, $type)),
        ];

        foreach ($operators as $op => $value) {
            if (!isset($map[$op])) {
                continue;
            }
            if (!\is_scalar($value)) {
                throw new FilterValueException(sprintf(
                    'Filter "%s" expects a single value for operator "%s".',
                    $context->column,
                    (string)$op,
                ));
            }
            $qb->andWhere(($map[$op])($value));
        }
    }

    public function preResolve(FilterDefinition $definition): FilterDefinition
    {
        return $this->typeResolver->preResolveType($definition);
    }

    public function hasConstraint(FilterContext $context): bool
    {
        return \is_array($context->value)
            && array_intersect(self::OPERATORS, array_keys($context->value)) !== [];
    }
}
