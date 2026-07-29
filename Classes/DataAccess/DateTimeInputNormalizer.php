<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

/**
 * Normalises incoming datetime values before they reach TYPO3's DataHandler.
 *
 * The API contract is symmetric: {@see \MaikSchneider\TcaApi\Serializer\DateTimeValueFormatter}
 * always emits a genuine UTC instant in ISO 8601 (ATOM), so an instant sent by a
 * client must be persisted as that same instant. DataHandler on its own does not
 * honour that contract, and it breaks differently per core version:
 *
 *   TYPO3 v13 — for columns WITHOUT dbType, DataHandler assumes any non-integer
 *   input follows the backend JavaScript convention (server-local wall-clock time
 *   mislabelled as "Z") and subtracts the server's UTC offset from the timestamp:
 *
 *       $value = (new \DateTime((string)$value))->getTimestamp();
 *       $value -= (int)date('Z', $value);   // DataHandler.php:2144-2151
 *
 *   A correct instant is therefore shifted by the server offset *at the event's own
 *   date*, so the error follows DST and cannot be compensated with a constant.
 *   Core acknowledges the defect with a `@todo` on the very next line.
 *
 *   TYPO3 v14 — the mangling above was removed, but a *fully unqualified* string
 *   ("2026-08-15 12:30:00") is parsed with `new \DateTimeImmutable($value)`, i.e.
 *   in the server's timezone rather than as UTC.
 *
 * Both are neutralised the same way — by handing DataHandler a value it cannot
 * reinterpret:
 *
 *   - Unix timestamp columns (no dbType) receive an **int**. DataHandler guards the
 *     whole mangling block with `!MathUtility::canBeInterpretedAsInteger($value)`,
 *     so an int bypasses it entirely and is stored verbatim.
 *   - Native columns (dbType date/datetime/time) receive an ISO 8601 string with an
 *     **explicit UTC offset**, which both core versions parse to the correct instant.
 *
 * Values that already are integers, and values that cannot be parsed as a date, are
 * passed through untouched — this class normalises, it never validates.
 *
 * @see https://github.com/maikschneider/tca-api/issues/170
 */
final class DateTimeInputNormalizer
{
    /** dbType values that make a column a native SQL date/time column. */
    private const NATIVE_DB_TYPES = ['date', 'datetime', 'time'];

    /**
     * Normalise every datetime column in a full DataHandler datamap.
     *
     * Applied at the single write choke point so the main record and all related
     * records created in the same datamap get identical treatment.
     *
     * @param array<string, array<string|int, array>> $dataMap
     * @return array<string, array<string|int, array>>
     */
    public function normalizeDataMap(array $dataMap): array
    {
        foreach ($dataMap as $table => $records) {
            foreach ($records as $id => $record) {
                $dataMap[$table][$id] = $this->normalizeRecord((string)$table, $record);
            }
        }

        return $dataMap;
    }

    /**
     * Normalise every datetime column of a single record.
     */
    public function normalizeRecord(string $table, array $record): array
    {
        foreach ($record as $column => $value) {
            $config = $GLOBALS['TCA'][$table]['columns'][$column]['config'] ?? null;

            if (!\is_array($config) || ($config['type'] ?? '') !== 'datetime') {
                continue;
            }

            $record[$column] = $this->normalizeValue($value, $config);
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $config The TCA column config
     */
    private function normalizeValue(mixed $value, array $config): mixed
    {
        // Empty values are TYPO3's "no date" sentinels — core maps them per column.
        if ($value === null || $value === '') {
            return $value;
        }

        // An integer is already a raw Unix timestamp and bypasses core's mangling.
        if (\is_int($value) || (\is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return $value;
        }

        if (!\is_string($value)) {
            return $value;
        }

        try {
            // The second argument only applies when $value carries no timezone
            // designator, which is exactly the "assume UTC" fallback we want:
            // the read path emits UTC, so an unqualified value is read back as UTC.
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            // Not a date — leave it for validation/core to reject.
            return $value;
        }

        $dbType = $config['dbType'] ?? null;

        if (\in_array($dbType, self::NATIVE_DB_TYPES, true)) {
            // Native column: keep a string, but force an explicit UTC offset so neither
            // core version can reinterpret it in the server's timezone. Normalising to
            // UTC rather than echoing the client's offset also keeps dbType=date
            // deterministic — v14 truncates to midnight in whatever timezone the parsed
            // value carries, so an unnormalised "+13:00" could shift the stored day.
            return $date->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
        }

        // Unix timestamp column: an int is stored verbatim by every core version.
        return $date->getTimestamp();
    }
}
