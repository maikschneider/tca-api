<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Serializer;

use MaikSchneider\TcaApi\Serializer\DateTimeValueFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DateTimeValueFormatterTest extends TestCase
{
    private DateTimeValueFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new DateTimeValueFormatter();
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
}
