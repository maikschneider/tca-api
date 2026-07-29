<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\DataAccess;

use MaikSchneider\TcaApi\DataAccess\DateTimeInputNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/maikschneider/tca-api/issues/170
 */
final class DateTimeInputNormalizerTest extends TestCase
{
    private const TABLE = 'tx_test_domain_model_event';

    private DateTimeInputNormalizer $normalizer;

    /** @var array<string, mixed> */
    private array $originalTca;

    private string $originalTimeZone;

    protected function setUp(): void
    {
        $this->normalizer = new DateTimeInputNormalizer();

        $this->originalTimeZone = date_default_timezone_get();
        // A DST-observing zone: the whole point of the fix is that the result must not
        // depend on the server offset, so never assert this under UTC.
        date_default_timezone_set('Europe/Berlin');

        $this->originalTca = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA'][self::TABLE]['columns'] = [
            'title'         => ['config' => ['type' => 'input']],
            'starts_at'     => ['config' => ['type' => 'datetime']],
            'native_at'     => ['config' => ['type' => 'datetime', 'dbType' => 'datetime']],
            'native_day'    => ['config' => ['type' => 'datetime', 'dbType' => 'date']],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->originalTca;
        date_default_timezone_set($this->originalTimeZone);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function instantProvider(): array
    {
        return [
            // Summer — Europe/Berlin is UTC+02:00 (CEST)
            'summer Z'              => ['2026-08-15T12:30:00Z', 1786797000],
            // Winter — Europe/Berlin is UTC+01:00 (CET); a different offset from summer
            'winter Z'              => ['2026-12-24T17:00:00Z', 1798131600],
            // Explicit offsets resolve to the same instants as above
            'summer +02:00'         => ['2026-08-15T14:30:00+02:00', 1786797000],
            'winter -05:00'         => ['2026-12-24T12:00:00-05:00', 1798131600],
            // No timezone designator → interpreted as UTC, matching the read contract
            'unqualified ISO'       => ['2026-08-15T12:30:00', 1786797000],
            'database format'       => ['2026-08-15 12:30:00', 1786797000],
        ];
    }

    #[Test]
    #[DataProvider('instantProvider')]
    public function timestampColumnBecomesIntUnixTimestamp(string $input, int $expected): void
    {
        $result = $this->normalizer->normalizeRecord(self::TABLE, ['starts_at' => $input]);

        // An int bypasses DataHandler's DST-buggy mangling block entirely.
        self::assertSame($expected, $result['starts_at']);
    }

    #[Test]
    #[DataProvider('instantProvider')]
    public function nativeColumnKeepsStringButGainsExplicitOffset(string $input, int $expected): void
    {
        $result = $this->normalizer->normalizeRecord(self::TABLE, ['native_at' => $input]);

        self::assertIsString($result['native_at']);
        // Same instant, now unambiguous for both core versions.
        self::assertSame($expected, (new \DateTimeImmutable($result['native_at']))->getTimestamp());
        self::assertSame(
            (new \DateTimeImmutable('@' . $expected))->format(\DateTimeInterface::ATOM),
            $result['native_at'],
        );
    }

    #[Test]
    public function normalizationIsIdempotent(): void
    {
        $once  = $this->normalizer->normalizeRecord(self::TABLE, ['starts_at' => '2026-08-15T12:30:00Z']);
        $twice = $this->normalizer->normalizeRecord(self::TABLE, $once);

        self::assertSame($once, $twice);
    }

    #[Test]
    public function integerInputIsPassedThroughUnchanged(): void
    {
        $result = $this->normalizer->normalizeRecord(self::TABLE, [
            'starts_at' => 1786797000,
        ]);

        self::assertSame(1786797000, $result['starts_at']);
    }

    #[Test]
    public function integerStringInputIsPassedThroughUnchanged(): void
    {
        // Left as a string so DataHandler's canBeInterpretedAsInteger() guard still
        // short-circuits, without this class inventing a type change.
        $result = $this->normalizer->normalizeRecord(self::TABLE, ['starts_at' => '1786797000']);

        self::assertSame('1786797000', $result['starts_at']);
    }

    #[Test]
    public function emptyAndNullSentinelsArePreserved(): void
    {
        $result = $this->normalizer->normalizeRecord(self::TABLE, [
            'starts_at' => '',
            'native_at' => null,
        ]);

        self::assertSame('', $result['starts_at']);
        self::assertNull($result['native_at']);
    }

    #[Test]
    public function unparseableValueIsLeftForValidationToReject(): void
    {
        $result = $this->normalizer->normalizeRecord(self::TABLE, ['starts_at' => 'not-a-date']);

        self::assertSame('not-a-date', $result['starts_at']);
    }

    #[Test]
    public function nonDatetimeColumnsAreUntouched(): void
    {
        $result = $this->normalizer->normalizeRecord(self::TABLE, [
            'title' => '2026-08-15T12:30:00Z',
        ]);

        self::assertSame('2026-08-15T12:30:00Z', $result['title']);
    }

    #[Test]
    public function columnsWithoutTcaAreUntouched(): void
    {
        $result = $this->normalizer->normalizeRecord(self::TABLE, [
            'pid'     => 1,
            'unknown' => '2026-08-15T12:30:00Z',
        ]);

        self::assertSame(1, $result['pid']);
        self::assertSame('2026-08-15T12:30:00Z', $result['unknown']);
    }

    #[Test]
    public function everyRecordOfEveryTableInTheDatamapIsNormalized(): void
    {
        $dataMap = $this->normalizer->normalizeDataMap([
            self::TABLE => [
                'NEW_primary' => ['starts_at' => '2026-08-15T12:30:00Z'],
                42            => ['starts_at' => '2026-12-24T17:00:00Z'],
            ],
        ]);

        self::assertSame(1786797000, $dataMap[self::TABLE]['NEW_primary']['starts_at']);
        self::assertSame(1798131600, $dataMap[self::TABLE][42]['starts_at']);
    }
}
