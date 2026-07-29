<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Formats raw datetime values from TCA columns to ISO 8601 (ATOM) strings.
 *
 * Handles both persistence modes:
 *   - Unix timestamp (integer) — when no dbType is set
 *   - Native SQL datetime string — when dbType is 'datetime', 'date', or 'time'
 *
 * Returns null for empty/zero values (TYPO3's sentinel for "no date").
 */
final class DateTimeValueFormatter
{
    /** @var array<string, string> Maps dbType to the PHP date format used for parsing. */
    private const DB_TYPE_FORMATS = [
        'datetime' => 'Y-m-d H:i:s',
        'date'     => 'Y-m-d',
        'time'     => 'H:i:s',
    ];

    /** MySQL zero-date sentinels that map to null (no value stored). */
    private const ZERO_DATE_SENTINELS = ['0000-00-00 00:00:00', '0000-00-00'];

    /**
     * Format a raw datetime value to ISO 8601 (ATOM).
     *
     * @param mixed       $value  The raw database value
     * @param string|null $dbType The TCA dbType setting (null for Unix timestamp mode)
     * @return string|null ISO 8601 string or null for empty values
     */
    public function format(mixed $value, ?string $dbType): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($dbType === null) {
            // Unix timestamp mode
            return $this->formatUnixTimestamp($value);
        }

        // Native SQL datetime mode
        return $this->formatNativeDatetime($value, $dbType);
    }

    private function formatUnixTimestamp(mixed $value): ?string
    {
        $intValue = (int)$value;

        // 0 is TYPO3's sentinel for "empty" in Unix timestamp mode
        if ($intValue === 0) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('U', (string)$intValue);
        if ($date === false) {
            return null;
        }

        return $date->format(\DateTimeInterface::ATOM);
    }

    private function formatNativeDatetime(mixed $value, string $dbType): ?string
    {
        $stringValue = (string)$value;

        if ($stringValue === '' || in_array($stringValue, self::ZERO_DATE_SENTINELS, true)) {
            return null;
        }

        $format = self::DB_TYPE_FORMATS[$dbType] ?? self::DB_TYPE_FORMATS['datetime'];
        // The '!' prefix resets all date/time fields to the Unix epoch before parsing,
        // ensuring date-only values get T00:00:00 and time-only values get 1970-01-01.
        $date = \DateTimeImmutable::createFromFormat(
            '!' . $format,
            $stringValue,
            $this->nativeStorageTimeZone($dbType),
        );

        if ($date === false) {
            // Fallback: return raw value if parsing fails
            return $stringValue;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    /**
     * The timezone a native SQL column's wall-clock value is stored in.
     *
     * This is a core-version-dependent convention, not a choice this extension makes:
     *
     *   TYPO3 v13 writes native columns through `gmdate()` (DataHandler.php:2177-2181),
     *   so the stored wall clock is UTC.
     *
     *   TYPO3 v14 converts to the server timezone before formatting
     *   (`QueryHelper::transformDateTimeToDatabaseValue()`, "native DATETIME values are
     *   stored in server LOCALTIME"), and reads them back the same way
     *   (`DateTimeFactory`: "The database always contains server localtime in native
     *   fields"). The stored wall clock is therefore server-local.
     *
     * The conversion applies to `dbType=datetime` only — v14 leaves `date` and `time`
     * columns in the parsed value's own timezone, matching v13's UTC output.
     */
    private function nativeStorageTimeZone(string $dbType): \DateTimeZone
    {
        if ($dbType === 'datetime' && (new Typo3Version())->getMajorVersion() >= 14) {
            return new \DateTimeZone(date_default_timezone_get());
        }

        return new \DateTimeZone('UTC');
    }
}
