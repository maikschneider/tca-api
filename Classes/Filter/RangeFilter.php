<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

final class RangeFilter implements FilterInterface
{
    public function __construct(
        private readonly TcaSchemaFactory $schemaFactory,
    ) {
    }

    public function apply(QueryBuilder $qb, string $column, array $filterConfig): void
    {
        $operators = $filterConfig['value'];
        if (!\is_array($operators)) {
            return;
        }

        $type = $this->resolveType($filterConfig);

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

    /**
     * Resolution order:
     *   1. Explicit `type` option in the filter config (escape hatch)
     *   2. Type inferred from the TCA column configuration
     *   3. null  → fall back to autodetection from the request value
     */
    private function resolveType(array $filterConfig): ?string
    {
        if (isset($filterConfig['type']) && \is_string($filterConfig['type'])) {
            return $filterConfig['type'];
        }

        $table  = $filterConfig['_table']  ?? null;
        $column = $filterConfig['_column'] ?? null;
        if (!\is_string($table) || !\is_string($column)) {
            return null;
        }

        return $this->detectTypeFromTca($table, $column);
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
