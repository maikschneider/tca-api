<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Resolves explicit or TCA-derived types and binds scalar or array parameters.
 * Without a resolved type, RangeFilter autodetects from the value;
 * ExactFilter uses string binding to preserve leading zeros.
 */
final class ColumnTypeResolver
{
    public function __construct(
        private readonly TcaSchemaFactory $schemaFactory,
    ) {
    }

    /**
     * Bakes the detected type into the definition so the TCA lookup happens once at boot.
     */
    public function preResolveType(FilterDefinition $definition): FilterDefinition
    {
        if (\is_string($definition->option('type'))) {
            return $definition;
        }

        // Guard for unit-test / empty-table contexts
        if ($definition->table === '') {
            return $definition;
        }

        $detected = $this->detect($definition->table, $definition->column);

        return $detected !== null ? $definition->withOptions(['type' => $detected]) : $definition;
    }

    public function resolveType(FilterContext $context): ?string
    {
        $explicit = $context->option('type');
        if (\is_string($explicit)) {
            return $explicit;
        }

        return $this->detect($context->table, $context->column);
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
    public function detect(string $table, string $column): ?string
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

    public function namedParameter(QueryBuilder $qb, mixed $value, ?string $type): string
    {
        [$cast, $paramType] = $this->resolveParameter($value, $type);

        return $qb->createNamedParameter($cast, $paramType);
    }

    /**
     * Binds a list of values as an array parameter for an `IN` / `NOT IN` comparison.
     * Values are cast per resolved type; the array type is `PARAM_INT_ARRAY` only when
     * every value resolves to `ParameterType::INTEGER`, otherwise `PARAM_STR_ARRAY`.
     * @param list<scalar> $values
     */
    public function namedArrayParameter(QueryBuilder $qb, array $values, ?string $type): string
    {
        $cast = [];
        $isInt = true;
        foreach ($values as $value) {
            [$castValue, $paramType] = $this->resolveParameter($value, $type);
            $cast[] = $castValue;
            $isInt  = $isInt && $paramType === ParameterType::INTEGER;
        }

        return $qb->createNamedParameter(
            $cast,
            $isInt ? Connection::PARAM_INT_ARRAY : Connection::PARAM_STR_ARRAY,
        );
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
