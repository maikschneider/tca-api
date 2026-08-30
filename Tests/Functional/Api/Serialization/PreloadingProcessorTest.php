<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestPreloadingFileProcessor;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestPreloadingProcessor;

/**
 * A processor implementing PreloadingProcessorInterface is handed the whole page
 * once, before serialization starts, so its own lookups can be batched instead
 * of paying per row.
 */
final class PreloadingProcessorTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        TestPreloadingProcessor::reset();
        TestPreloadingFileProcessor::reset();

        $this->registerResource('preload-articles', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'preload-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
                'storagePid'   => 1,
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
            ],
            'virtualProperties' => [
                'batched' => [
                    'groups'    => ['list', 'show'],
                    'processor' => TestPreloadingProcessor::class,
                ],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    public function testPrepareRunsOnceForTheWholeCollection(): void
    {
        $response = $this->executeApiRequest('/_api/preload-articles');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(3, $body['hydra:totalItems']);
        self::assertSame(1, TestPreloadingProcessor::$prepareCalls);
        self::assertSame([3], TestPreloadingProcessor::$preparedRowCounts);
    }

    public function testProcessIsServedFromWhatPrepareCollected(): void
    {
        $response = $this->executeApiRequest('/_api/preload-articles');
        $body     = $this->decodeResponseBody($response);

        $values = array_column($body['hydra:member'], 'batched');
        self::assertSame(['batched-1', 'batched-2', 'batched-3'], $values);
    }

    public function testSingleRecordRequestDoesNotPrepare(): void
    {
        $response = $this->executeApiRequest('/_api/preload-articles/1');
        $body     = $this->decodeResponseBody($response);

        // process() must still work without a prior prepare().
        self::assertSame(0, TestPreloadingProcessor::$prepareCalls);
        self::assertSame('unbatched', $body['batched']);
    }

    public function testFileBackedVirtualPropertySkipsThePreload(): void
    {
        // profile_photo is type=file, so FileFieldSerializer owns it and the column
        // processor never runs — preparing it would be a query for nothing.
        $this->registerResource('preload-articles', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'preload-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
                'storagePid'   => 1,
            ],
            'columns' => ['title' => ['groups' => ['list', 'show']]],
            'virtualProperties' => [
                'photo' => [
                    'groups'    => ['list', 'show'],
                    'column'    => 'profile_photo',
                    'processor' => TestPreloadingFileProcessor::class,
                ],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        $this->executeApiRequest('/_api/preload-articles');

        self::assertSame(0, TestPreloadingFileProcessor::$prepareCalls);
    }

    public function testPreparedBatchDoesNotOutliveTheCollection(): void
    {
        $this->executeApiRequest('/_api/preload-articles');
        self::assertSame(1, TestPreloadingProcessor::$prepareCalls);

        // A later single-record request on the same serializer instance must fall
        // back to an unprepared processor.
        $body = $this->decodeResponseBody($this->executeApiRequest('/_api/preload-articles/1'));

        self::assertSame(1, TestPreloadingProcessor::$prepareCalls);
        self::assertSame('unbatched', $body['batched']);
    }

    public function testSameTableEmbeddedRowOutsidePageDoesNotUsePreparedBatch(): void
    {
        $table = 'tx_myext_domain_model_article';
        $this->getConnectionPool()->getConnectionForTable($table)->update($table, ['parent_id' => 2], ['uid' => 1]);

        $this->registerResource('preload-articles', [
            'general' => [
                'table'        => $table,
                'resourceName' => 'preload-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
                'storagePid'   => 1,
            ],
            'columns' => [
                'title'     => ['groups' => ['list', 'show']],
                'parent_id' => ['groups' => ['list', 'show'], 'embed' => true],
            ],
            'virtualProperties' => [
                'batched' => [
                    'groups'    => ['list', 'show'],
                    'processor' => TestPreloadingProcessor::class,
                ],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        $body = $this->decodeResponseBody($this->executeApiRequest(
            '/_api/preload-articles',
            ['itemsPerPage' => 1],
        ));
        $article = $body['hydra:member'][0];

        self::assertSame('batched-1', $article['batched']);
        self::assertSame(2, $article['parent_id']['uid']);
        self::assertSame('unbatched', $article['parent_id']['batched']);
    }

    public function testSparseFieldsetSkipsThePreload(): void
    {
        $this->executeApiRequest('/_api/preload-articles', ['fields' => ['title']]);

        // The virtual property never runs, so it must not cost a preload either.
        self::assertSame(0, TestPreloadingProcessor::$prepareCalls);
    }
}
