<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

/**
 * Filters and normalizes writable column values for TYPO3 DataHandler.
 *
 * Array values (e.g. MM relation UIDs) are converted to comma-separated strings,
 * which is the format DataHandler expects for multi-value TCA fields.
 */
trait ColumnFilterTrait
{
    private function filterWritableColumns(array $body, array $config): array
    {
        $result = [];
        foreach ($config['columns'] as $column => $columnConfig) {
            if (($columnConfig['writable'] ?? false) && \array_key_exists($column, $body)) {
                $value = $body[$column];
                if (\is_array($value) && $this->isScalarList($value)) {
                    $result[$column] = implode(',', $value);
                    continue;
                }
                $result[$column] = $value;
            }
        }
        return $result;
    }

    private function isScalarList(array $value): bool
    {
        if (!array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!\is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }
}
