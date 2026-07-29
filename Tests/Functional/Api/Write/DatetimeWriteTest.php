<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for type=datetime TCA column writes.
 *
 * The read path (DateTimeValueFormatter) always emits a genuine UTC instant in
 * ISO 8601 (ATOM). These tests pin the write path to the same contract: an
 * ISO 8601 instant sent by a client must round-trip unchanged, regardless of
 * the server's timezone or the DST offset in effect at the event's own date.
 *
 * Covers both persistence modes:
 *   published_at → type=datetime, no dbType  (int Unix timestamp column)
 *   event_date   → type=datetime, dbType=datetime (native SQL DATETIME column)
 *
 * @see https://github.com/maikschneider/tca-api/issues/170
 */
final class DatetimeWriteTest extends ApiFunctionalTestCase
{
    private const CONFIG = [
        'general' => [
            'table' => 'tx_myext_domain_model_article',
            'resourceName' => 'datetime-write-articles',
            'resourceType' => 'Article',
            'operations' => ['list', 'show', 'create', 'update'],
            'storagePid' => 1,
        ],
        'columns' => [
            'title' => ['groups' => ['list', 'show', 'create', 'update']],
            'published_at' => ['groups' => ['list', 'show', 'create', 'update']],
            'event_date' => ['groups' => ['list', 'show', 'create', 'update']],
        ],
        'security' => [
            'list' => AccessRole::PUBLIC,
            'show' => AccessRole::PUBLIC,
            'create' => AccessRole::PUBLIC,
            'update' => AccessRole::PUBLIC,
        ],
    ];

    private string $originalTimeZone;

    protected function setUp(): void
    {
        parent::setUp();

        // The defect only shows up when the server is NOT on UTC, and its size follows
        // DST. Pin a DST-observing zone so this stays a real regression test on CI
        // runners, which default to UTC and would otherwise pass trivially.
        $this->originalTimeZone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['phpTimeZone'] = 'Europe/Berlin';

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimeZone);
        parent::tearDown();
    }

    /**
     * Each case is an ISO 8601 instant that must survive POST → GET unchanged.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function iso8601InstantProvider(): array
    {
        return [
            // Summer — Europe/Berlin is UTC+02:00 (CEST)
            'summer UTC (Z)' => ['2026-08-15T12:30:00Z', '2026-08-15T12:30:00+00:00'],
            // Winter — Europe/Berlin is UTC+01:00 (CET)
            'winter UTC (Z)' => ['2026-12-24T17:00:00Z', '2026-12-24T17:00:00+00:00'],
            // Explicit non-UTC offsets normalise to the same instant in UTC
            'explicit +02:00 offset' => ['2026-08-15T14:30:00+02:00', '2026-08-15T12:30:00+00:00'],
            'explicit -05:00 offset' => ['2026-12-24T12:00:00-05:00', '2026-12-24T17:00:00+00:00'],
            // Both sides of the 2026-10-25 DST boundary (Europe/Berlin CEST → CET)
            'day before DST end' => ['2026-10-24T12:00:00Z', '2026-10-24T12:00:00+00:00'],
            'day after DST end' => ['2026-10-26T12:00:00Z', '2026-10-26T12:00:00+00:00'],
            // Both sides of the 2027-03-28 DST boundary (Europe/Berlin CET → CEST)
            'day before DST start' => ['2027-03-27T12:00:00Z', '2027-03-27T12:00:00+00:00'],
            'day after DST start' => ['2027-03-29T12:00:00Z', '2027-03-29T12:00:00+00:00'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('iso8601InstantProvider')]
    public function testUnixTimestampColumnRoundTripsIso8601Instant(string $sent, string $expected): void
    {
        $this->registerResource('datetime-write-articles', self::CONFIG);

        $create = $this->executeApiWriteRequest('POST', '/_api/datetime-write-articles', [
            'title' => 'Datetime Write',
            'published_at' => $sent,
        ]);
        self::assertSame(201, $create->getStatusCode(), (string)$create->getBody());

        $uid = $this->decodeResponseBody($create)['uid'];

        $read = $this->executeApiRequest('/_api/datetime-write-articles/' . $uid);
        self::assertSame($expected, $this->decodeResponseBody($read)['published_at']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('iso8601InstantProvider')]
    public function testNativeDatetimeColumnRoundTripsIso8601Instant(string $sent, string $expected): void
    {
        $this->registerResource('datetime-write-articles', self::CONFIG);

        $create = $this->executeApiWriteRequest('POST', '/_api/datetime-write-articles', [
            'title' => 'Datetime Write',
            'event_date' => $sent,
        ]);
        self::assertSame(201, $create->getStatusCode(), (string)$create->getBody());

        $uid = $this->decodeResponseBody($create)['uid'];

        $read = $this->executeApiRequest('/_api/datetime-write-articles/' . $uid);
        self::assertSame($expected, $this->decodeResponseBody($read)['event_date']);
    }
}
