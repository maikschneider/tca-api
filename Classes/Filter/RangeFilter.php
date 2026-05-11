<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

final class RangeFilter implements FilterInterface, FilterPreResolvableInterface
{
    public function __construct(
        private readonly TcaSchemaFactory $schemaFactory,
    ) {
    }

    public function apply(QueryBuilder $qb, FilterContext $context): void
    {
        $operators = $context->value;
        if (!\is_array($operators)) {
            return;
        }

        $type = $this->resolveType($context);

        $map = [
            'gte' => fn (mixed $v) => $qb->expr()->gte($context->column, $this->namedParam($qb, $v, $type)),
            'lte' => fn (mixed $v) => $qb->expr()->lte($context->column, $this->namedParam($qb, $v, $type)),
            'gt'  => fn (mixed $v) => $qb->expr()->gt($context->column, $this->namedParam($qb, $v, $type)),
            'lt'  => fn (mixed $v) => $qb->expr()->lt($context->column, $this->namedParam($qb, $v, $type)),
        ];

        foreach ($operators as $op => $value) {
            if (isset($map[$op])) {
                $qb->andWhere(($map[$op])($value));
            }
        }
    }

    public function preResolve(FilterDefinition $definition): FilterDefinition
    {
        if (\is_string($definition->option('type'))) {
            return $definition;
        }

        // Guard for unit-test / empty-table contexts
        if ($definition->table === '') {
            return $definition;
        }

        $detected = $this->detectTypeFromTca($definition->table, $definition->column);

        return $detected !== null ? $definition->withOptions(['type' => $detected]) : $definition;
    }

    /**
     * Resolution order:
     *   1. Explicit `type` option in the filter config (escape hatch)
     *   2. Type inferred from the TCA column configuration
     *   3. null  → fall back to autodetection from the request value
     */
    private function resolveType(FilterContext $context): ?string
    {
        $explicit = $context->option('type');
        if (\is_string($explicit)) {
            return $explicit;
        }

        return $this->detectTypeFromTca($context->table, $context->column);
    }

    /**
     * Map TCA column types to a filter type:
     *   - number/integer  → int
     *   - number/decimal  → float
     *   - datetime stored as a native column (dbType set) → string
     *   - datetime stored as UNIX timestamp (no dbType)   → int
     *   - input with eval=int                             → int
     *   - everything else                                 → null  (autodetect)
     */
    private function detectTypeFromTca(string $table, string $column): ?string
    {
        if (!$this->schemaFactory->has($table)) {
            return null;
        }

        $schema = $this->schemaFactory->get($table);
        if (!$schema->hasField($column)) {
            return null;
        }

        $config = $schema->getField($column)->getConfiguration();
        $tcaType = $config['type'] ?? null;

        switch ($tcaType) {
            case 'number':
                return ($config['format'] ?? 'integer') === 'decimal' ? 'float' : 'int';

            case 'datetime':
                return isset($config['dbType']) ? 'string' : 'int';

            case 'input':
                $eval = (string)($config['eval'] ?? '');
                if ($eval !== '' && \in_array('int', array_map('trim', explode(',', $eval)), true)) {
                    return 'int';
                }
                return null;

            default:
                return null;
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
