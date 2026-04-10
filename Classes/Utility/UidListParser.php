<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Utility;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Utility for parsing TYPO3 UID lists stored as comma-separated strings in a column
 * (e.g. fe_users.usergroup = "1,2").
 */
final class UidListParser
{
    /**
     * Parse a comma-separated UID string into an array of positive integers.
     * Returns [] for empty or whitespace-only input.
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(GeneralUtility::intExplode(',', $raw, true)));
    }

    /**
     * Map an ordered UID array to rows from an indexed result set, preserving order.
     * UIDs not found in $indexed are filtered out.
     *
     * @param int[]              $uids    Ordered list of UIDs
     * @param array<int, array>  $indexed Result of DataRepository::findByIds — keyed by UID
     */
    public static function mapToRows(array $uids, array $indexed): array
    {
        return array_values(array_filter(array_map(fn (int $uid) => $indexed[$uid] ?? null, $uids)));
    }
}
