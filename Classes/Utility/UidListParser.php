<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Utility;

final class UidListParser
{
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
