<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;

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

        if (!TcaColumnDiscovery::isExplicitMode($config)) {
            // Default mode: accept all exposable TCA columns present in the body
            foreach (TcaColumnDiscovery::getExposableColumnNames($config['general']['table']) as $column) {
                if (\array_key_exists($column, $body)) {
                    $value = $body[$column];
                    $result[$column] = \is_array($value) ? implode(',', $value) : $value;
                }
            }
        } else {
            // Explicit mode
            foreach ($config['columns'] as $column => $columnConfig) {
                if (!TcaColumnDiscovery::isColumnWritable($columnConfig)) {
                    continue;
                }

                if (\array_key_exists($column, $body)) {
                    $value = $body[$column];
                    $result[$column] = \is_array($value) ? implode(',', $value) : $value;
                }
            }
        }

        // Strip ownership columns — server-managed and never client-writable
        foreach (array_unique(array_filter([
            $config['ownership']['column'] ?? null,
            $config['ownership']['setOnCreate'] ?? null,
        ])) as $col) {
            unset($result[$col]);
        }

        return $result;
    }
}
