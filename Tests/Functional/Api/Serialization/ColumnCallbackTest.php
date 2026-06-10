<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestColumnCallback;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestStaticValueProcessor;

/**
 * Functional tests for the 'callback' meta-key on normal columns.
 *
 * A column callback is invoked at the very end of serialization — after every
 * column, relation, and virtual property is resolved — with (serializedRow,
 * rawRow). Its return value replaces the column's value in the response.
 */
final class ColumnCallbackTest extends ApiFunctionalTestCase
{
    private const NAMES_CONFIG = [
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

    public function testCallbackTransformsTheSerializedColumnValue(): void
    {
        $config = self::NAMES_CONFIG;
        $config['columns']['title']['callback'] = [TestColumnCallback::class, 'upperTitle'];
        $this->registerResource('people', $config);

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('PERSON RECORD', $body['title']);
    }

    public function testCallbackReceivesRawRow(): void
    {
        $config = self::NAMES_CONFIG;
        $config['columns']['title']['callback'] = [TestColumnCallback::class, 'rawFirstName'];
        $this->registerResource('people', $config);

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        self::assertSame('raw:John', $body['title']);
    }

    public function testCallbackSeesOtherResolvedColumns(): void
    {
        // fullName reads first_name + last_name from the serialized row, which
        // proves the callback runs after all columns have been resolved.
        $config = self::NAMES_CONFIG;
        $config['columns']['title']['callback'] = [TestColumnCallback::class, 'fullName'];
        $this->registerResource('people', $config);

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        self::assertSame('John Doe', $body['title']);
        // The source columns are untouched.
        self::assertSame('John', $body['first_name']);
        self::assertSame('Doe', $body['last_name']);
    }

    public function testCallbackRunsAfterRelationResolving(): void
    {
        // color_id is embedded; the callback on title reads the resolved
        // relation out of the serialized row — only possible if it runs last.
        $this->registerResource('embed-people', [
            'general' => [
                'table' => 'tx_myext_domain_model_article',
                'resourceName' => 'embed-people',
                'resourceType' => 'Person',
                'operations' => ['list', 'show'],
            ],
            'columns' => [
                'title' => [
                    'groups' => ['list', 'show'],
                    'callback' => [TestColumnCallback::class, 'colorName'],
                ],
                'color_id' => ['groups' => ['list', 'show'], 'embed' => true],
            ],
            'order' => [
                'allowed' => ['uid'],
                'default' => ['uid' => 'asc'],
            ],
        ]);

        $response = $this->executeApiRequest('/_api/embed-people/50');
        $body = $this->decodeResponseBody($response);

        self::assertIsArray($body['color_id']);
        self::assertSame('Red', $body['color_id']['name']);
        // Callback read the embedded relation name, proving it ran afterwards.
        self::assertSame('Red', $body['title']);
    }

    public function testColumnCallbackRunsBeforeVirtualProperties(): void
    {
        // title is upper-cased by a column callback; a virtual property then
        // echoes title. If column callbacks ran after virtual properties, the
        // VP would see the un-transformed "Person Record" instead.
        $config = self::NAMES_CONFIG;
        $config['columns']['title']['callback'] = [TestColumnCallback::class, 'upperTitle'];
        $config['virtualProperties'] = [
            'titleEcho' => [
                'callback' => [TestColumnCallback::class, 'echoTitle'],
                'groups' => ['list', 'show'],
            ],
        ];
        $this->registerResource('people', $config);

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        self::assertSame('PERSON RECORD', $body['title']);
        self::assertSame('vp:PERSON RECORD', $body['titleEcho']);
    }

    public function testVirtualPropertyCallbackRunsOnTopOfProcessorOutput(): void
    {
        // A VP with BOTH a processor and a callback: the processor sets the base
        // value ('static-value'), then the callback decorates it. Previously the
        // callback was skipped whenever a processor was defined.
        $config = self::NAMES_CONFIG;
        $config['virtualProperties'] = [
            'computed' => [
                'processor' => TestStaticValueProcessor::class,
                'callback' => [TestColumnCallback::class, 'decorateComputed'],
                'groups' => ['list', 'show'],
            ],
        ];
        $this->registerResource('people', $config);

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        self::assertSame('STATIC-VALUE!', $body['computed']);
    }

    public function testVirtualPropertyCallbackSeesEarlierVirtualProperty(): void
    {
        // 'computed' (processor) is declared before 'combined' (callback), so
        // the callback sees the already-resolved 'computed' value.
        $config = self::NAMES_CONFIG;
        $config['virtualProperties'] = [
            'computed' => [
                'processor' => TestStaticValueProcessor::class,
                'groups' => ['list', 'show'],
            ],
            'combined' => [
                'callback' => [TestColumnCallback::class, 'readComputedVp'],
                'groups' => ['list', 'show'],
            ],
        ];
        $this->registerResource('people', $config);

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        self::assertSame('static-value', $body['computed']);
        self::assertSame('seen:static-value', $body['combined']);
    }

    public function testCallbackRespectsExplicitModeVisibilityGate(): void
    {
        // title carries a callback but is restricted to the 'show' group; it
        // must not appear (callback included) in a list response.
        $config = self::NAMES_CONFIG;
        $config['columns']['title'] = [
            'groups' => ['show'],
            'callback' => [TestColumnCallback::class, 'upperTitle'],
        ];
        $this->registerResource('people', $config);

        $response = $this->executeApiRequest('/_api/people');
        $body = $this->decodeResponseBody($response);

        self::assertArrayNotHasKey('title', $body['hydra:member'][0] ?? []);
    }

    public function testCallbackIsSkippedWhenColumnExcludedBySparseFieldset(): void
    {
        $config = self::NAMES_CONFIG;
        $config['columns']['title']['callback'] = [TestColumnCallback::class, 'upperTitle'];
        $this->registerResource('people', $config);

        $response = $this->executeApiRequest('/_api/people/10', ['fields' => ['first_name']]);
        $body = $this->decodeResponseBody($response);

        // title was not requested → neither the column nor its callback applies.
        self::assertArrayNotHasKey('title', $body);
        self::assertSame('John', $body['first_name']);
    }

    public function testColumnWithoutCallbackSerializesNormally(): void
    {
        $this->registerResource('people', self::NAMES_CONFIG);

        $response = $this->executeApiRequest('/_api/people/10');
        $body = $this->decodeResponseBody($response);

        self::assertSame('Person Record', $body['title']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_with_names.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_embed.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
    }
}
