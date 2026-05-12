<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for type=json TCA column serialization.
 *
 * Fixture data:
 *   Article 501 → meta = {"color":"red","size":42}  (valid JSON object)
 *   Article 502 → meta = NULL                       (null DB value)
 *   Article 503 → meta = "not-valid-json{"          (invalid JSON)
 */
final class JsonFieldSerializationTest extends ApiFunctionalTestCase
{
    private const BASE_CONFIG = [
        'general' => [
            'table' => 'tx_myext_domain_model_article',
            'resourceName' => 'json-articles',
            'resourceType' => 'Article',
            'operations' => ['list', 'show'],
        ],
        'columns' => [
            'title' => ['groups' => ['list', 'show']],
            'meta' => ['groups' => ['list', 'show']],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_json.csv');
    }

    public function testValidJsonIsDecodedToArray(): void
    {
        $this->registerResource('json-articles', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/json-articles/501');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('meta', $body);
        self::assertIsArray($body['meta']);
        self::assertSame('red', $body['meta']['color']);
        self::assertSame(42, $body['meta']['size']);
    }

    public function testNullJsonRemainsNull(): void
    {
        $this->registerResource('json-articles', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/json-articles/502');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('meta', $body);
        self::assertNull($body['meta']);
    }

    public function testInvalidJsonFallsBackToRawString(): void
    {
        $this->registerResource('json-articles', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/json-articles/503');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('meta', $body);
        self::assertIsString($body['meta']);
        self::assertSame('not-valid-json{', $body['meta']);
    }

    public function testCustomProcessorOverridesAutoDecoding(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns']['meta'] = [
            'groups' => ['list', 'show'],
            'processor' => \MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestEchoProcessor::class,
        ];
        $this->registerResource('json-articles', $config);

        $response = $this->executeApiRequest('/_api/json-articles/501');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        // The processor receives the raw value; it is NOT auto-decoded
        self::assertArrayHasKey('meta', $body);
        self::assertIsString($body['meta']);
    }
}
