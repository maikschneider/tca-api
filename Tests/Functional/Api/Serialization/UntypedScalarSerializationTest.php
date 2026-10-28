<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Information\Typo3Version;

final class UntypedScalarSerializationTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Extensions/test_record_types',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->registerResource('scalar-items', [
            'general' => [
                'table' => 'tx_testrecordtypes_scalar',
                'resourceName' => 'scalar-items',
                'resourceType' => 'ScalarItem',
                'operations' => ['show'],
            ],
        ]);
    }

    public function testDatabaseGeneratedScalarColumnsMatchTheResponseContract(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_testrecordtypes_scalar')->insert('tx_testrecordtypes_scalar', [
            'uid' => 1,
            'pid' => 1,
            'nullable_choice' => 1,
            'radio_choice' => 1,
        ]);

        // executeApiRequest also validates the response against the generated OpenAPI schema.
        $response = $this->executeApiRequest('/_api/scalar-items/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('slug', $body);
        self::assertNull($body['slug']);
        self::assertSame('1', $body['radio_choice']);
        self::assertSame((new Typo3Version())->getMajorVersion() >= 14 ? 1 : '1', $body['nullable_choice']);
        if ((new Typo3Version())->getMajorVersion() >= 14) {
            self::assertArrayHasKey('country', $body);
            self::assertNull($body['country']);
        }
    }

    public function testNullSelectValueMatchesTheResponseContract(): void
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::markTestSkipped('TYPO3 13 does not generate nullable select columns.');
        }
        $this->getConnectionPool()->getConnectionForTable('tx_testrecordtypes_scalar')->insert('tx_testrecordtypes_scalar', [
            'uid' => 1,
            'pid' => 1,
            'nullable_choice' => null,
        ]);

        $response = $this->executeApiRequest('/_api/scalar-items/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('nullable_choice', $body);
        self::assertNull($body['nullable_choice']);
    }
}
