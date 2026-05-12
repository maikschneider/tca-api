<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for type=flex TCA column serialization.
 *
 * Fixture data:
 *   Article 601 → pi_flexform = valid FlexForm XML with settings.myField = "Hello World"
 *   Article 602 → pi_flexform = empty string (no data)
 */
final class FlexFormSerializationTest extends ApiFunctionalTestCase
{
    private const BASE_CONFIG = [
        'general' => [
            'table' => 'tx_myext_domain_model_article',
            'resourceName' => 'flex-articles',
            'resourceType' => 'Article',
            'operations' => ['list', 'show'],
        ],
        'columns' => [
            'title' => ['groups' => ['list', 'show']],
            'pi_flexform' => ['groups' => ['list', 'show']],
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_flexform.csv');
    }

    public function testValidFlexFormXmlIsDecodedToArray(): void
    {
        $this->registerResource('flex-articles', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/flex-articles/601');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('pi_flexform', $body);
        self::assertIsArray($body['pi_flexform']);
        self::assertArrayHasKey('data', $body['pi_flexform']);
        self::assertSame(
            'Hello World',
            $body['pi_flexform']['data']['sDEF']['lDEF']['settings.myField']['vDEF']
        );
    }

    public function testEmptyFlexFormReturnsNull(): void
    {
        $this->registerResource('flex-articles', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/flex-articles/602');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('pi_flexform', $body);
        self::assertNull($body['pi_flexform']);
    }

    public function testInvalidFlexFormResultsRawValue(): void
    {
        $this->registerResource('flex-articles', self::BASE_CONFIG);
        $response = $this->executeApiRequest('/_api/flex-articles/603');
        $body = $this->decodeResponseBody($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('pi_flexform', $body);
        self::assertSame('<?xml version="1.0" encoding="utf-8">', $body['pi_flexform']);
    }

    public function testCustomProcessorOverridesFlexFormDecoding(): void
    {
        $config = self::BASE_CONFIG;
        $config['columns']['pi_flexform'] = [
            'groups' => ['list', 'show'],
            'processor' => \MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestEchoProcessor::class,
        ];
        $this->registerResource('flex-articles', $config);

        $response = $this->executeApiRequest('/_api/flex-articles/601');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('pi_flexform', $body);
        // With a custom processor, the raw XML string is passed through
        self::assertIsString($body['pi_flexform']);
        self::assertStringContainsString('<T3FlexForms>', $body['pi_flexform']);
    }
}
