<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Utility\TcaColumnDiscovery;

/**
 * Filters and normalizes writable column values for TYPO3 DataHandler.
 *
 * Array values (e.g. MM relation UIDs) are converted to comma-separated strings,
 * which is the format DataHandler expects for multi-value TCA fields.
 */
trait ColumnFilterTrait
{
    private function filterWritableColumns(array $body, ApiDefinition $config): array
    {
        $result = [];

        if (!$config->isExplicitMode) {
            // Default mode: accept all exposable TCA columns present in the body
            foreach (TcaColumnDiscovery::getExposableColumnNames($config->table) as $column) {
                if (\array_key_exists($column, $body)) {
                    $value = $body[$column];
                    $result[$column] = \is_array($value) ? implode(',', $value) : $value;
                }
            }
        } else {
            // Explicit mode
            foreach ($config->columns as $column => $columnDef) {
                if (!$columnDef->isWritable()) {
                    continue;
                }
                // Password columns must never be accepted via API input.
                if (($GLOBALS['TCA'][$config->table]['columns'][$column]['config']['type'] ?? '') === 'password') {
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
            $config->ownershipColumn,
            $config->ownershipSetOnCreate,
        ])) as $col) {
            unset($result[$col]);
        }

        return $result;
    }
}
