<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Utility;

/**
 * Static utility for TCA introspection — column discovery.
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
            if (isset($excluded[$colName]) || str_starts_with((string)$colName, 't3ver_')) {
                continue;
            }
            $result[] = (string)$colName;
        }

        return self::$columnNameCache[$table] = $result;
    }
}
