<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestCountingProcessor;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestDisplayNameCallable;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestStaticValueProcessor;

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
        ],
        'columns' => [
            'title' => ['groups' => ['list', 'show']],
            'first_name' => ['groups' => ['list', 'show']],
            'last_name' => ['groups' => ['list', 'show']],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
    ];

    public function testGetItemReturns200(): void
    {
        $this->registerResource('people', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/people/10');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testGetItemResponseContainsDisplayNameKey(): void
    {
        $this->registerResource('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                    'groups' => ['list', 'show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('displayName', $body);
    }

    public function testDisplayNameConcatenatesLastNameAndFirstName(): void
    {
        $this->registerResource('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                    'groups' => ['list', 'show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        self::assertSame('Doe, John', $body['displayName']);
    }

    public function testVirtualPropertyAppearsAfterRealColumns(): void
    {
        $this->registerResource('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                    'groups' => ['list', 'show'],
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

    public function testVirtualPropertyWithShowGroupExcludedFromList(): void
    {
        $this->registerResource('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                    'groups' => ['show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/people');
        $body = $this->decodeResponseBody($response);

        self::assertArrayNotHasKey('displayName', $body['hydra:member'][0] ?? []);
    }

    public function testVirtualPropertyWithShowGroupIncludedInShow(): void
    {
        $this->registerResource('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                    'groups' => ['show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('displayName', $body);
    }

    public function testVirtualPropertyWithNoGroupsExcludedInExplicitMode(): void
    {
        $this->registerResource('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        // BASE_CONFIG has columns with 'groups' → explicit mode is active
        // VP has no 'groups' → excluded
        self::assertArrayNotHasKey('displayName', $body);
    }

    public function testProcessorBasedVirtualPropertyExcludedByVisibilityGate(): void
    {
        $this->registerResource('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'computedValue' => [
                    'processor' => TestStaticValueProcessor::class,
                    'groups' => ['show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/people');
        $body = $this->decodeResponseBody($response);

        // 'show' group only — must be absent from list
        self::assertArrayNotHasKey('computedValue', $body['hydra:member'][0] ?? []);
    }

    public function testResourceWithoutVirtualPropertiesSerializesNormally(): void
    {
        $this->registerResource('people', self::BASE_CONFIG);

        $response = $this->executeApiRequest('/_api/people/10');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        self::assertArrayNotHasKey('displayName', $body);
    }

    public function testVirtualPropertyOmittedWhenNotInFields(): void
    {
        $this->registerResource('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                    'groups' => ['list', 'show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/people/10', ['fields' => ['title']]);
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('title', $body);
        self::assertArrayNotHasKey('displayName', $body);
    }

    public function testVirtualPropertyReturnedWhenListedInFields(): void
    {
        $this->registerResource('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                    'groups' => ['list', 'show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/people/10', ['fields' => ['displayName']]);
        $body = $this->decodeResponseBody($response);

        self::assertSame('Doe, John', $body['displayName']);
        self::assertArrayNotHasKey('title', $body);
    }

    public function testCollectionAppliesFieldsToVirtualProperties(): void
    {
        $this->registerResource('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                    'groups' => ['list', 'show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/people', ['fields' => ['title']]);
        $body = $this->decodeResponseBody($response);

        self::assertNotEmpty($body['hydra:member']);
        foreach ($body['hydra:member'] as $member) {
            self::assertArrayNotHasKey('displayName', $member);
        }
    }

    public function testProcessorIsNotRunForVirtualPropertyOutsideFields(): void
    {
        $this->registerResource('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'computedValue' => [
                    'processor' => TestCountingProcessor::class,
                    'groups' => ['list', 'show'],
                ],
            ],
        ]));

        TestCountingProcessor::reset();
        $this->executeApiRequest('/_api/people', ['fields' => ['title']]);

        self::assertSame(0, TestCountingProcessor::$invocations);
    }

    public function testProcessorRunsForVirtualPropertyInsideFields(): void
    {
        $this->registerResource('people', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'computedValue' => [
                    'processor' => TestCountingProcessor::class,
                    'groups' => ['list', 'show'],
                ],
            ],
        ]));

        TestCountingProcessor::reset();
        $response = $this->executeApiRequest('/_api/people/10', ['fields' => ['computedValue']]);
        $body = $this->decodeResponseBody($response);

        self::assertSame('counted-value', $body['computedValue']);
        self::assertSame(1, TestCountingProcessor::$invocations);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_with_names.csv');
    }
}
