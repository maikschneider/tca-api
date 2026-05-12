<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for FileFieldSerializer processor auto-detection.
 *
 * Verifies that omitting an explicit 'processor' config causes the serializer
 * to pick the best FileProcessorInterface implementation based on the TCA
 * 'allowed' extensions of the field:
 *
 *   - profile_photo (allowed: jpg,jpeg,png,gif,webp — all images) → ImageProcessor
 *     Output has 'cropVariants' key and image-specific metadata fields.
 *
 *   - downloads (allowed: pdf,csv,xlsx,docx — non-image types) → FileProcessor
 *     Output has 'mimeType'/'fileSize' but no 'cropVariants' key.
 *
 * Both scenarios use article fixtures 410/411 from articles_with_files.csv.
 */
final class FileProcessorAutoDetectionTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_with_files.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_reference.csv');

        // Register the articles resource WITHOUT explicit processors on any file column.
        // profile_photo and downloads both rely on auto-detection from TCA allowed.
        $this->registerResource('articles', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
                'storagePid'   => 1,
            ],
            'columns' => [
                'profile_photo' => ['groups' => ['show']],
                'downloads'     => ['groups' => ['show']],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── profile_photo: all-image allowed → ImageProcessor ────────────────────

    public function testProfilePhotoAutoDetectsImageProcessor(): void
    {
        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('profile_photo', $body);
        self::assertIsArray($body['profile_photo']);
    }

    public function testProfilePhotoAutoDetectedImageProcessorHasCropVariants(): void
    {
        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        // ImageProcessor always emits cropVariants (empty array when no crop JSON stored)
        self::assertArrayHasKey('cropVariants', $body['profile_photo']);
    }

    public function testProfilePhotoAutoDetectedImageProcessorHasImageMetadata(): void
    {
        $response = $this->executeApiRequest('/_api/articles/410');
        $body     = $this->decodeResponseBody($response);

        $metadata = $body['profile_photo']['metadata'];
        // ImageProcessor adds 'alternative' and 'copyright' — FileProcessor does not
        self::assertArrayHasKey('alternative', $metadata);
        self::assertArrayHasKey('copyright', $metadata);
    }

    // ── downloads: non-image allowed → FileProcessor ─────────────────────────

    public function testDownloadsAutoDetectsFileProcessor(): void
    {
        $response = $this->executeApiRequest('/_api/articles/411');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('downloads', $body);
        self::assertIsArray($body['downloads']);
        self::assertCount(1, $body['downloads']);
    }

    public function testDownloadsAutoDetectedFileProcessorHasNoImageMetadata(): void
    {
        $response = $this->executeApiRequest('/_api/articles/411');
        $body     = $this->decodeResponseBody($response);

        $item = $body['downloads'][0];
        // FileProcessor does not emit cropVariants — ImageProcessor would
        self::assertArrayNotHasKey('cropVariants', $item);
    }

    public function testDownloadsAutoDetectedFileProcessorHasBasicFileFields(): void
    {
        $response = $this->executeApiRequest('/_api/articles/411');
        $body     = $this->decodeResponseBody($response);

        $item = $body['downloads'][0];
        self::assertArrayHasKey('publicUrl', $item);
        self::assertArrayHasKey('mimeType', $item);
        self::assertArrayHasKey('fileSize', $item);
    }

    public function testDownloadsAutoDetectedFileProcessorMetadataLacksAlternativeKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/411');
        $body     = $this->decodeResponseBody($response);

        $metadata = $body['downloads'][0]['metadata'];
        // FileProcessor metadata has title + description only; no 'alternative' or 'copyright'
        self::assertArrayNotHasKey('alternative', $metadata);
        self::assertArrayNotHasKey('copyright', $metadata);
    }
}
