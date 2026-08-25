<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\QueryCounting\CountingDriverMiddleware;
use MaikSchneider\TcaApi\Tests\Functional\QueryCounting\QueryCounter;

/**
 * Guards the file-reference preload against an N+1 regression.
 *
 * File references used to be resolved one record at a time, which made the
 * query count of a collection response grow linearly with the page size. The
 * assertions below compare two responses over *disjoint* record sets of
 * different size, so any per-record query shows up as a difference.
 *
 * Fixture data (articles_file_bulk): articles 500–511, each with one
 * `downloads` file reference to its own sys_file record.
 */
final class FileReferenceQueryCountTest extends ApiFunctionalTestCase
{
    protected array $configurationToUseInTestInstance = [
        'DB' => [
            'Connections' => [
                'Default' => [
                    'driverMiddlewares' => [
                        'tca-api/query-counter' => ['target' => CountingDriverMiddleware::class],
                    ],
                ],
            ],
        ],
    ];

    private const CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'bulk-files',
            'resourceType' => 'BulkFileArticle',
            'operations'   => ['list', 'show'],
            'maxItemsPerPage' => 100,
        ],
        'columns' => [
            'title'     => ['groups' => ['list', 'show']],
            'downloads' => ['groups' => ['list', 'show'], 'processor' => FileProcessor::class],
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_file_bulk.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_bulk.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_metadata_bulk.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_reference_bulk.csv');
    }

    public function testFileColumnDoesNotAddQueriesPerRecord(): void
    {
        $this->registerResource('bulk-files', self::CONFIG);

        self::assertSame(0, $this->marginalQueryCost('/_api/bulk-files'));
    }

    public function testVirtualPropertyOnSameFileColumnDoesNotAddQueriesPerRecord(): void
    {
        $config = self::CONFIG;
        $config['virtualProperties'] = [
            'attachments' => [
                'column'    => 'downloads',
                'processor' => FileProcessor::class,
                'groups'    => ['list', 'show'],
            ],
        ];
        $this->registerResource('bulk-files', $config);

        self::assertSame(0, $this->marginalQueryCost('/_api/bulk-files'));
    }

    public function testFileColumnStillSerializesUnderPreload(): void
    {
        $this->registerResource('bulk-files', self::CONFIG);

        $body = $this->decodeResponseBody(
            $this->executeApiRequest('/_api/bulk-files', ['itemsPerPage' => 12, 'page' => 1]),
        );

        $members = $body['hydra:member'];
        self::assertCount(12, $members);

        foreach ($members as $index => $member) {
            self::assertCount(1, $member['downloads'], 'article ' . $member['uid']);
            self::assertSame('/fileadmin/user_upload/bulk-' . $index . '.pdf', $member['downloads'][0]['publicUrl']);
            self::assertSame('application/pdf', $member['downloads'][0]['mimeType']);
            self::assertSame('Bulk Title ' . $index, $member['downloads'][0]['metadata']['title']);
        }
    }

    /**
     * Queries a request spends per record, measured as the difference between a
     * 2-record page and a 6-record page over records that share no file.
     */
    private function marginalQueryCost(string $path): int
    {
        // Warm the caches the first request in a process pays for, on records
        // neither measured page touches.
        $this->executeApiRequest($path, ['itemsPerPage' => 2, 'page' => 2]);

        QueryCounter::start();
        $this->executeApiRequest($path, ['itemsPerPage' => 2, 'page' => 1]);
        $small = QueryCounter::stop();

        QueryCounter::start();
        $this->executeApiRequest($path, ['itemsPerPage' => 6, 'page' => 2]);
        $large = QueryCounter::stop();

        return intdiv($large - $small, 4);
    }
}
