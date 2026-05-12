<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestEchoProcessor;

/**
 * Functional tests for type=imageManipulation serialization.
 *
 * Fixture records (colors_image_manipulation.csv):
 *   200 → crop_settings = valid JSON crop string  (decoded to array)
 *   201 → crop_settings = ''                      (empty string; raw fallback)
 *   202 → crop_settings = 'not-valid-json'        (invalid JSON; raw fallback)
 */
final class ImageManipulationTest extends ApiFunctionalTestCase
{
    private const RESOURCE = 'crop-colors';

    private const BASE_CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_color',
            'resourceName' => self::RESOURCE,
            'resourceType' => 'CropColor',
            'operations'   => ['list', 'show'],
        ],
        'columns' => [
            'name'          => ['groups' => ['list', 'show']],
            'crop_settings' => ['groups' => ['list', 'show']],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
        'security' => [
            'list' => AccessRole::PUBLIC,
            'show' => AccessRole::PUBLIC,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors_image_manipulation.csv');
    }

    // ── show endpoint ────────────────────────────────────────────────────────

    public function testShowDecodesJsonToCropAreaArray(): void
    {
        $this->registerResource(self::RESOURCE, self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/' . self::RESOURCE . '/200');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['crop_settings']);
        $cropArea = $body['crop_settings']['default']['cropArea'] ?? null;
        self::assertIsArray($cropArea);
        self::assertSame(0, $cropArea['x']);
        self::assertSame(0, $cropArea['y']);
        self::assertSame(1, $cropArea['width']);
        self::assertSame(1, $cropArea['height']);
    }

    // ── list endpoint ────────────────────────────────────────────────────────

    public function testListDecodesJsonToArray(): void
    {
        $this->registerResource(self::RESOURCE, self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/' . self::RESOURCE);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        // Records ordered uid asc; UID 200 is first in the fixture
        $first = $body['hydra:member'][0];
        self::assertSame(200, $first['uid']);
        self::assertIsArray($first['crop_settings']);
        self::assertArrayHasKey('default', $first['crop_settings']);
    }

    // ── empty string ─────────────────────────────────────────────────────────

    public function testEmptyStringReturnsRawValue(): void
    {
        $this->registerResource(self::RESOURCE, self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/' . self::RESOURCE . '/201');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $body['crop_settings']);
    }

    // ── invalid JSON ─────────────────────────────────────────────────────────

    public function testInvalidJsonReturnsRawString(): void
    {
        $this->registerResource(self::RESOURCE, self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/' . self::RESOURCE . '/202');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('not-valid-json', $body['crop_settings']);
    }

    // ── processor bypass ─────────────────────────────────────────────────────

    public function testProcessorOnColumnSkipsAutoDecoding(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns']['crop_settings']['processor'] = TestEchoProcessor::class;

        $this->registerResource(self::RESOURCE, $config);

        $response = $this->executeApiRequest('/_api/' . self::RESOURCE . '/200');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        // Auto-decode is suppressed when a processor is configured; raw JSON string reaches the processor
        self::assertIsString($body['crop_settings']);
        self::assertStringContainsString('cropArea', $body['crop_settings']);
    }
}
