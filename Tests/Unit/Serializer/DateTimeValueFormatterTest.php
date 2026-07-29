<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Serializer;

use MaikSchneider\TcaApi\Serializer\DateTimeValueFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Information\Typo3Version;

final class DateTimeValueFormatterTest extends TestCase
{
    private DateTimeValueFormatter $formatter;

    private string $originalTimeZone;

    protected function setUp(): void
    {
        $this->formatter = new DateTimeValueFormatter();

        // A native dbType=datetime column stores a bare wall clock whose timezone is a
        // core-version convention: UTC on TYPO3 v13 (written via gmdate()), server
        // localtime on v14. Pinning UTC makes both conventions coincide, so the shared
        // expectations below hold on every core version in the support matrix.
        // The localtime convention gets its own test.
        $this->originalTimeZone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimeZone);
    }

    #[Test]
    public function nullValueReturnsNull(): void
    {
        self::assertNull($this->formatter->format(null, null));
        self::assertNull($this->formatter->format(null, 'datetime'));
    }

    #[Test]
    public function zeroUnixTimestampReturnsNull(): void
    {
        self::assertNull($this->formatter->format(0, null));
        self::assertNull($this->formatter->format('0', null));
    }

    #[Test]
    public function unixTimestampIsFormattedAsIso8601(): void
    {
        // 2024-01-01 00:00:00 UTC
        $result = $this->formatter->format(1704067200, null);
        self::assertSame('2024-01-01T00:00:00+00:00', $result);
    }

    #[Test]
    public function unixTimestampStringIsFormattedAsIso8601(): void
    {
        $result = $this->formatter->format('1704067200', null);
        self::assertSame('2024-01-01T00:00:00+00:00', $result);
    }

    #[Test]
    public function nativeDatetimeIsFormattedAsIso8601(): void
    {
        $result = $this->formatter->format('2024-01-01 00:00:00', 'datetime');
        self::assertSame('2024-01-01T00:00:00+00:00', $result);
    }

    #[Test]
    public function nativeDateIsFormattedAsIso8601(): void
    {
        $result = $this->formatter->format('2024-06-15', 'date');
        self::assertSame('2024-06-15T00:00:00+00:00', $result);
    }

    #[Test]
    public function nativeTimeIsFormattedAsIso8601(): void
    {
        self::assertSame('1970-01-01T14:30:00+00:00', $this->formatter->format('14:30:00', 'time'));
        // midnight is a valid time value, not a zero sentinel
        self::assertSame('1970-01-01T00:00:00+00:00', $this->formatter->format('00:00:00', 'time'));
    }

    #[Test]
    public function emptyNativeDatetimeReturnsNull(): void
    {
        self::assertNull($this->formatter->format('0000-00-00 00:00:00', 'datetime'));
        self::assertNull($this->formatter->format('0000-00-00', 'date'));
        self::assertNull($this->formatter->format('', 'datetime'));
    }

    #[Test]
    public function negativeUnixTimestampIsFormatted(): void
    {
        // 1969-12-31T23:59:59+00:00
        $result = $this->formatter->format(-1, null);
        self::assertSame('1969-12-31T23:59:59+00:00', $result);
    }

    #[Test]
    public function invalidNativeDatetimeReturnsFallback(): void
    {
        $result = $this->formatter->format('not-a-date', 'datetime');
        self::assertSame('not-a-date', $result);
    }

    /**
     * Native dbType=datetime values are read back in the timezone core stores them in:
     * server localtime from TYPO3 v14 on, UTC before that. Either way the output is a
     * genuine instant normalised to UTC — which is what makes the write path in
     * {@see \MaikSchneider\TcaApi\DataAccess\DateTimeInputNormalizer} round-trip.
     *
     * @see https://github.com/maikschneider/tca-api/issues/170
     */
    #[Test]
    public function nativeDatetimeIsReadInCoreStorageTimeZone(): void
    {
        date_default_timezone_set('Europe/Berlin');

        $isLocaltimeStorage = (new Typo3Version())->getMajorVersion() >= 14;

        // Summer — Europe/Berlin is UTC+02:00 (CEST)
        self::assertSame(
            $isLocaltimeStorage ? '2024-06-15T08:30:00+00:00' : '2024-06-15T10:30:00+00:00',
            $this->formatter->format('2024-06-15 10:30:00', 'datetime'),
        );

        // Winter — Europe/Berlin is UTC+01:00 (CET). The offset differs from summer,
        // which is precisely why a constant correction cannot work.
        self::assertSame(
            $isLocaltimeStorage ? '2024-12-24T09:30:00+00:00' : '2024-12-24T10:30:00+00:00',
            $this->formatter->format('2024-12-24 10:30:00', 'datetime'),
        );

        // dbType=date and dbType=time stay UTC on every core version — v14 only
        // converts the timezone for dbType=datetime.
        self::assertSame('2024-06-15T00:00:00+00:00', $this->formatter->format('2024-06-15', 'date'));
        self::assertSame('1970-01-01T14:30:00+00:00', $this->formatter->format('14:30:00', 'time'));
    }
}
