<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Utility;

/**
 * Static utility for TCA introspection — column discovery.
 *
 * The config-facing methods (isExplicitMode, isColumnReadable, isColumnWritable) have been
 * moved to ApiDefinition::$isExplicitMode and ColumnDefinition::isReadable()/isWritable().
 * The stubs below are kept for backward compatibility and will be removed in a future version.
 */
final class TcaColumnDiscovery
{
    /** @var array<string, list<string>> Per-table cache for exposable column names. */
    private static array $columnNameCache = [];

    /**
     * Returns TCA column names for $table, excluding TYPO3 system/ctrl fields
     * (deleted, hidden, starttime, endtime, language, workspace, etc.).
     *
     * Result is cached per table to avoid repeated TCA reads on collection serialization.
     *
     * @return list<string>
     */
    public static function getExposableColumnNames(string $table): array
    {
        if (isset(self::$columnNameCache[$table])) {
            return self::$columnNameCache[$table];
        }

        $tca = $GLOBALS['TCA'][$table] ?? [];
        if ($tca === [] || !isset($tca['columns'])) {
            return self::$columnNameCache[$table] = [];
        }

        $ctrl = $tca['ctrl'] ?? [];
        $excluded = [];

        // enablecolumns values are the actual column names
        foreach ($ctrl['enablecolumns'] ?? [] as $colName) {
            $excluded[$colName] = true;
        }

        // ctrl keys whose string values are column names
        $ctrlColumnKeys = [
            'delete', 'tstamp', 'crdate', 'cruser_id', 'editlock', 'sortby',
            'languageField', 'transOrigPointerField', 'transOrigDiffSourceField',
            'translationSource', 'origUid',
        ];
        foreach ($ctrlColumnKeys as $key) {
            if (isset($ctrl[$key]) && \is_string($ctrl[$key])) {
                $excluded[$ctrl[$key]] = true;
            }
        }

        $result = [];
        foreach (array_keys($tca['columns']) as $colName) {
            if (isset($excluded[$colName]) || str_starts_with($colName, 't3ver_')) {
                continue;
            }
            $result[] = $colName;
        }

        return self::$columnNameCache[$table] = $result;
    }

    /**
     * @deprecated Use ApiDefinition::$isExplicitMode instead.
     */
    public static function isExplicitMode(array $config): bool
    {
        foreach ($config['columns'] ?? [] as $columnConfig) {
            if (\array_key_exists('groups', $columnConfig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @deprecated Use ColumnDefinition::isWritable() instead.
     */
    public static function isColumnWritable(array $columnConfig, string $operation = ''): bool
    {
        $groups = $columnConfig['groups'] ?? [];

        if ($operation !== '') {
            return \in_array($operation, $groups, true);
        }

        return \in_array('create', $groups, true) || \in_array('update', $groups, true);
    }

    /**
     * @deprecated Use ColumnDefinition::isReadable() instead.
     */
    public static function isColumnReadable(array $columnConfig, string $operation = ''): bool
    {
        $groups = $columnConfig['groups'] ?? [];

        if ($operation !== '') {
            return \in_array($operation, $groups, true);
        }

        return \in_array('list', $groups, true) || \in_array('show', $groups, true);
    }

    /**
     * Clears the static column name cache. Useful in tests that modify TCA between runs.
     */
    public static function clearCache(): void
    {
        self::$columnNameCache = [];
    }
}
