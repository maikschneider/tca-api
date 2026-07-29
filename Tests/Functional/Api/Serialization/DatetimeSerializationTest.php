<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for type=datetime TCA column serialization.
 *
 * Verifies that datetime columns are consistently serialized to ISO 8601,
 * regardless of persistence mode (Unix timestamp vs native SQL datetime).
 *
 * Fixture data:
 *   Article 601 → published_at = 1704067200 (Unix), event_date = "2024-06-15 10:30:00" (native)
 *   Article 602 → published_at = 0 (empty sentinel), event_date = NULL
 */
final class DatetimeSerializationTest extends ApiFunctionalTestCase
{
    private const BASE_CONFIG = [
        'general' => [
            'table' => 'tx_myext_domain_model_article',
            'resourceName' => 'datetime-articles',
            'resourceType' => 'Article',
            'operations' => ['list', 'show'],
        ],
        'columns' => [
            'title' => ['groups' => ['list', 'show']],
            'published_at' => ['groups' => ['list', 'show']],
            'event_date' => ['groups' => ['list', 'show']],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
    ];

    private string $originalTimeZone;

    protected function setUp(): void
    {
        parent::setUp();

        // event_date is a native dbType=datetime column, so its stored wall clock is
        // UTC on TYPO3 v13 and server localtime on v14. Pinning UTC makes the two
        // conventions coincide, keeping the expectations below valid on both.
        // DatetimeWriteTest covers the non-UTC case via a full round-trip.
        $this->originalTimeZone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['phpTimeZone'] = 'UTC';

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_datetime.csv');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimeZone);
        parent::tearDown();
    }

    public function testDatetimeColumnsAreSerializedAsIso8601(): void
    {
        $this->registerResource('datetime-articles', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/datetime-articles/701');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());

        // Unix timestamp (no dbType) → ISO 8601
        self::assertSame('2024-01-01T00:00:00+00:00', $body['published_at']);

        // Native datetime (dbType=datetime) → ISO 8601, normalised to UTC
        self::assertSame('2024-06-15T10:30:00+00:00', $body['event_date']);
    }

    public function testEmptyDatetimeValuesAreSerializedAsNull(): void
    {
        $this->registerResource('datetime-articles', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/datetime-articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());

        // Unix timestamp 0 → null (TYPO3 empty sentinel)
        self::assertNull($body['published_at']);

        // NULL native datetime → null
        self::assertNull($body['event_date']);
    }
}
