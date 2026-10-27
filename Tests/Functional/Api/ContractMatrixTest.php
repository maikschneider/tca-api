<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Filter\RangeFilter;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Serializes a record with a column of every TCA type and relation shape, filled
 * and empty, through list and show, and lets ApiFunctionalTestCase validate each
 * response against the OpenAPI spec (#271). A mismatch the baseline doesn't list
 * fails here, so this test pins what the spec fixes of #259 established.
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
            // Serialized like the file column it reads (#263).
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

        // itemsPerPage 1: the first page has no previous link, the last no next link (#261).
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

        // #265
        self::assertSame(1, $filled['is_active']);
        self::assertSame(2, $filled['level']);
        self::assertSame('dark', $filled['mode']);
        self::assertNull($empty['starts_at']);
        // #262: lists although maxitems is 1 / renderType is selectSingle, one record for a oneToOne category
        self::assertIsList($filled['parent']);
        self::assertIsList($filled['tags']);
        self::assertIsString($filled['main_category']);
        // #263, #264
        self::assertIsList($filled['teaser']);
        self::assertSame('Alt text', $filled['images'][0]['metadata']['alternative']);
        self::assertArrayHasKey('width', $filled['images'][0]);
    }

    public function testRangeFilterSentAsDocumentedFilters(): void
    {
        $this->registerResource('contract-records', self::RESOURCE);

        // deepObject, as the spec documents the parameter (#260)
        $response = $this->executeApiRequest('/_api/contract-records', ['starts_at' => ['gte' => 1700000000]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->decodeResponseBody($response)['hydra:totalItems']);
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
    }
}
