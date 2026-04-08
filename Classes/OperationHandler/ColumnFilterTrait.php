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
                $result[$column] = \is_array($value) ? implode(',', $value) : $value;
            }
        }
        return $result;
    }
}
