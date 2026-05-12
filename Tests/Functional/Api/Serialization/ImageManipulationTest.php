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
 *   200 → crop_settings = valid JSON crop string  (should decode to array)
 *   201 → crop_settings = ''                      (empty string; raw fallback)
 *   202 → crop_settings = 'not-valid-json'        (invalid JSON; raw fallback)
 */
final class ImageManipulationTest extends ApiFunctionalTestCase
{
    private const BASE_CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_color',
            'resourceName' => 'crop-colors',
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

    public function testShowDecodesValidJsonToArray(): void
    {
        $this->registerResource('crop-colors', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/crop-colors/200');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('crop_settings', $body);
        self::assertIsArray($body['crop_settings']);
        self::assertArrayHasKey('default', $body['crop_settings']);
    }

    public function testShowDecodedArrayContainsCropArea(): void
    {
        $this->registerResource('crop-colors', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/crop-colors/200');
        $body     = $this->decodeResponseBody($response);

        $cropArea = $body['crop_settings']['default']['cropArea'] ?? null;

        self::assertIsArray($cropArea);
        self::assertSame(0, $cropArea['x']);
        self::assertSame(0, $cropArea['y']);
        self::assertSame(1, $cropArea['width']);
        self::assertSame(1, $cropArea['height']);
    }

    // ── list endpoint ────────────────────────────────────────────────────────

    public function testListDecodesValidJsonToArray(): void
    {
        $this->registerResource('crop-colors', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/crop-colors');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());

        $record200 = null;
        foreach ($body['hydra:member'] as $member) {
            if ($member['uid'] === 200) {
                $record200 = $member;
                break;
            }
        }

        self::assertNotNull($record200, 'Record 200 not found in list response');
        self::assertIsArray($record200['crop_settings']);
        self::assertArrayHasKey('default', $record200['crop_settings']);
    }

    // ── empty string ─────────────────────────────────────────────────────────

    public function testEmptyStringReturnsRawValue(): void
    {
        $this->registerResource('crop-colors', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/crop-colors/201');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('crop_settings', $body);
        // Empty string is not valid JSON → raw fallback (empty string returned)
        self::assertSame('', $body['crop_settings']);
    }

    // ── invalid JSON ─────────────────────────────────────────────────────────

    public function testInvalidJsonReturnsRawString(): void
    {
        $this->registerResource('crop-colors', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/crop-colors/202');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('crop_settings', $body);
        self::assertIsString($body['crop_settings']);
        self::assertSame('not-valid-json', $body['crop_settings']);
    }

    // ── processor bypass ─────────────────────────────────────────────────────

    public function testProcessorOnColumnSkipsAutoDecoding(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns']['crop_settings']['processor'] = TestEchoProcessor::class;

        $this->registerResource('crop-colors', $config);

        $response = $this->executeApiRequest('/_api/crop-colors/200');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('crop_settings', $body);
        // EchoProcessor returns the value unchanged; auto-decode was skipped → raw JSON string
        self::assertIsString($body['crop_settings']);
        self::assertStringContainsString('cropArea', $body['crop_settings']);
    }
}
