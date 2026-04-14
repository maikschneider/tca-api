<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestDisplayNameCallable;
use MaikSchneider\TcaApi\Tests\Functional\Fixtures\TestEchoProcessor;

/**
 * Functional tests for virtualProperties with a `column` reference.
 *
 * When a VP config has a `column` key it sources its value from that DB column:
 *   - Scalar column  → raw $row[$column] is forwarded to the ColumnProcessor
 *   - File column    → file refs are fetched and run through the FileProcessor
 *   - Callback VP    → callback still receives ($result, $row) unchanged
 *
 * Fixture data (articles_with_files):
 *   Article 70 → title='Article With Photo', profile_photo → sys_file_reference uid=1 (image/jpeg)
 *   Article 71 → title='Article With Downloads'
 *   Article 72 → no file references
 */
final class VirtualPropertyColumnReferenceTest extends ApiFunctionalTestCase
{
    private const BASE_CONFIG = [
        'general' => [
            'table'         => 'tx_myext_domain_model_article',
            'resourceName'  => 'file-articles',
            'resourceType'  => 'FileArticle',
            'operations'    => ['list', 'show'],
            'itemsPerPage'  => 20,
        ],
        'columns' => [
            'title'         => ['groups' => ['list', 'show']],
            'profile_photo' => ['groups' => ['list', 'show']],
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

    // ── Scalar column reference ───────────────────────────────────────────────

    public function testScalarColumnReferenceKeyAppearsInResponse(): void
    {
        ApiRegistry::register('file-articles', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'titleCopy' => [
                    'column'    => 'title',
                    'processor' => TestEchoProcessor::class,
                    'groups'    => ['list', 'show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/file-articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('titleCopy', $body);
    }

    public function testScalarColumnReferenceForwardsRawValueToProcessor(): void
    {
        ApiRegistry::register('file-articles', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'titleCopy' => [
                    'column'    => 'title',
                    'processor' => TestEchoProcessor::class,
                    'groups'    => ['list', 'show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/file-articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertSame('Article With Photo', $body['titleCopy']);
    }

    public function testProcessorWithoutColumnReceivesNull(): void
    {
        ApiRegistry::register('file-articles', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'noSource' => [
                    'processor' => TestEchoProcessor::class,
                    'groups'    => ['list', 'show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/file-articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertNull($body['noSource']);
    }

    // ── File column reference ─────────────────────────────────────────────────

    public function testFileColumnReferenceReturnsArray(): void
    {
        ApiRegistry::register('file-articles', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'thumbnail' => [
                    'column' => 'profile_photo',
                    'groups' => ['list', 'show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/file-articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('thumbnail', $body);
        self::assertIsArray($body['thumbnail']);
    }

    public function testFileColumnReferenceHasPublicUrlKey(): void
    {
        ApiRegistry::register('file-articles', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'thumbnail' => [
                    'column' => 'profile_photo',
                    'groups' => ['list', 'show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/file-articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('publicUrl', $body['thumbnail']);
    }

    public function testFileColumnReferenceWithExplicitFileProcessorOmitsCropVariants(): void
    {
        ApiRegistry::register('file-articles', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'thumbnail' => [
                    'column'    => 'profile_photo',
                    'processor' => FileProcessor::class,
                    'groups'    => ['list', 'show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/file-articles/70');
        $body     = $this->decodeResponseBody($response);

        // FileProcessor does not produce cropVariants; ImageProcessor (default) does
        self::assertArrayNotHasKey('cropVariants', $body['thumbnail']);
    }

    public function testFileColumnReferenceReturnsNullWhenNoFileLinked(): void
    {
        ApiRegistry::register('file-articles', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'thumbnail' => [
                    'column' => 'profile_photo',
                    'groups' => ['list', 'show'],
                ],
            ],
        ]));

        // Article 72 has no file references
        $response = $this->executeApiRequest('/_api/file-articles/72');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('thumbnail', $body);
        self::assertNull($body['thumbnail']);
    }

    // ── Callback VP with column key ───────────────────────────────────────────

    public function testCallbackVirtualPropertyWithColumnKeyStillReceivesResultAndRow(): void
    {
        ApiRegistry::register('file-articles', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'displayName' => [
                    'column'   => 'title',
                    'callback' => [TestDisplayNameCallable::class, 'displayName'],
                    'groups'   => ['list', 'show'],
                ],
            ],
        ]));

        // TestDisplayNameCallable builds "last_name, first_name" from $result
        // articles_with_files has no first_name/last_name — both null → "null, null"
        // This test just asserts the key exists and no exception is thrown
        $response = $this->executeApiRequest('/_api/file-articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('displayName', $body);
    }

    // ── Unknown column reference ──────────────────────────────────────────────

    public function testUnknownColumnReferenceDoesNotThrowAndReturnsNull(): void
    {
        ApiRegistry::register('file-articles', array_merge(self::BASE_CONFIG, [
            'virtualProperties' => [
                'mystery' => [
                    'column'    => 'nonexistent_column',
                    'processor' => TestEchoProcessor::class,
                    'groups'    => ['list', 'show'],
                ],
            ],
        ]));

        $response = $this->executeApiRequest('/_api/file-articles/70');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        self::assertNull($body['mystery']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_with_files.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_reference.csv');
    }
}
