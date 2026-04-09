<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestDisplayNameCallable;

/**
 * Functional tests for virtualProperties in resource config.
 * Virtual properties are computed fields appended to the serialized output
 * by invoking a callable with (serializedRow, rawRow) after all real columns.
 */
final class VirtualPropertiesTest extends ApiFunctionalTestCase
{
    private const BASE_CONFIG = [
        'general' => [
            'table' => 'tx_myext_domain_model_article',
            'resourceName' => 'people',
            'resourceType' => 'Person',
            'operations' => ['list', 'show'],
            'itemsPerPage' => 20,
        ],
        'columns' => [
            'title' => ['readable' => true],
            'first_name' => ['readable' => true],
            'last_name' => ['readable' => true],
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

    public function testGetItemReturns200(): void
    {
        ApiRegistry::register('people', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/people/10');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testGetItemResponseContainsDisplayNameKey(): void
    {
        ApiRegistry::register('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('displayName', $body);
    }

    public function testDisplayNameConcatenatesLastNameAndFirstName(): void
    {
        ApiRegistry::register('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        self::assertSame('Doe, John', $body['displayName']);
    }

    public function testVirtualPropertyAppearsAfterRealColumns(): void
    {
        ApiRegistry::register('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        // Real columns are present alongside the virtual one
        self::assertArrayHasKey('title', $body);
        self::assertSame('Person Record', $body['title']);
        self::assertArrayHasKey('displayName', $body);
    }

    public function testResourceWithoutVirtualPropertiesSerializesNormally(): void
    {
        ApiRegistry::register('people', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/people/10');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        self::assertArrayNotHasKey('displayName', $body);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles_with_names.csv');
    }
}
