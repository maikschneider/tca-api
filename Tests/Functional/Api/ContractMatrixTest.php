<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Filter\RangeFilter;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Serializes a record with a column of every TCA type and relation shape, filled
 * and empty, through list and show, and lets ApiFunctionalTestCase validate each
 * response against the OpenAPI spec. A mismatch the baseline doesn't list fails
 * here, so this test pins the shapes the spec describes.
 *
 * Fixture table: tx_testcontract_record (extension test_contract).
 */
final class ContractMatrixTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../Fixtures/Extensions/test_contract',
    ];

    private const RESOURCE = [
        'general' => [
            'table'        => 'tx_testcontract_record',
            'resourceName' => 'contract-records',
            'resourceType' => 'ContractRecord',
            'operations'   => ['list', 'show'],
            'itemsPerPage' => 1,
        ],
        'columns' => [
            'images'     => ['image' => ['cropVariant' => 'default']],
            'attachment' => ['processor' => FileProcessor::class],
        ],
        'virtualProperties' => [
            // Serialized like the file column it reads.
            'teaser' => ['column' => 'images'],
        ],
        'filters' => [
            'starts_at' => RangeFilter::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file_metadata.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Extensions/test_contract/Tests/contract_records.csv');
    }

    public function testEveryPageAndRecordMatchesTheSpec(): void
    {
        $this->registerResource('contract-records', self::RESOURCE);

        // itemsPerPage 1: the first page has no previous link, the last no next link.
        foreach ([1, 2] as $page) {
            $response = $this->executeApiRequest('/_api/contract-records', ['page' => $page]);
            self::assertSame(200, $response->getStatusCode());
        }
        foreach ([1, 2] as $uid) {
            $response = $this->executeApiRequest('/_api/contract-records/' . $uid);
            self::assertSame(200, $response->getStatusCode());
        }
    }

    public function testValuesHaveTheShapesTheSpecDescribes(): void
    {
        $this->registerResource('contract-records', self::RESOURCE);

        $filled = $this->decodeResponseBody($this->executeApiRequest('/_api/contract-records/1'));
        $empty  = $this->decodeResponseBody($this->executeApiRequest('/_api/contract-records/2'));

        // Untyped scalars as the serializer sends them.
        self::assertSame(1, $filled['is_active']);
        self::assertSame(2, $filled['level']);
        self::assertSame('dark', $filled['mode']);
        self::assertNull($empty['starts_at']);
        // Lists although maxitems is 1 / renderType is selectSingle, one record for a oneToOne category
        self::assertIsList($filled['parent']);
        self::assertIsList($filled['tags']);
        self::assertIsString($filled['main_category']);
        // A virtual property over a file column, and the ImageProcessor fields.
        self::assertIsList($filled['teaser']);
        self::assertSame('Alt text', $filled['images'][0]['metadata']['alternative']);
        self::assertArrayHasKey('width', $filled['images'][0]);
    }

    public function testRangeFilterSentAsDocumentedFilters(): void
    {
        $this->registerResource('contract-records', self::RESOURCE);

        // deepObject, as the spec documents the parameter
        $response = $this->executeApiRequest('/_api/contract-records', ['starts_at' => ['gte' => 1700000000]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->decodeResponseBody($response)['hydra:totalItems']);

        $spec       = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'));
        $parameters = array_column($spec['paths']['/_api/contract-records']['get']['parameters'], null, 'name');
        self::assertSame('deepObject', $parameters['starts_at']['style']);
        self::assertTrue($parameters['starts_at']['explode']);
        self::assertSame('object', $parameters['starts_at']['schema']['type']);
    }

    public function testEmbeddedRecordsMatchTheSpec(): void
    {
        $config = self::RESOURCE;
        $config['columns']['parent']        = ['embed' => ['depth' => 2]];
        $config['columns']['main_category'] = ['embed' => true];
        $this->registerResource('contract-records', $config);

        foreach ([1, 2] as $uid) {
            self::assertSame(200, $this->executeApiRequest('/_api/contract-records/' . $uid)->getStatusCode());
        }

        // An embedded object also validates against "object or IRI", so the schema itself is checked.
        $spec       = $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'));
        $properties = $spec['components']['schemas']['ContractRecordRead']['properties'];
        self::assertSame('object', $properties['main_category']['oneOf'][0]['type']);
        self::assertSame(['type' => 'null'], $properties['main_category']['oneOf'][1]);
        self::assertSame('object', $properties['parent']['items']['oneOf'][0]['type']);
        self::assertSame('iri-reference', $properties['parent']['items']['oneOf'][1]['format']);
    }
}
