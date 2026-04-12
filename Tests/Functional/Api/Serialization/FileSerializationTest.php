<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Serialization;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for FAL file/image serialization.
 *
 * Fixture data:
 *   Article 70 → profile_photo=sys_file_reference uid=1 (image/jpeg, profile.jpg)
 *   Article 71 → downloads=sys_file_reference uid=2 (application/pdf, document.pdf)
 *   Article 72 → no file references
 *
 * Tests assert JSON structure (keys present, types) without depending on
 * actual image processing or real files on disk.
 */
final class FileSerializationTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_with_files.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_reference.csv');
    }

    // ── profile_photo (hasOne, ImageProcessor) ───────────────────────────────

    public function testProfilePhotoIsObjectWhenFileLinked(): void
    {
        $response = $this->executeApiRequest('/_api/articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('profile_photo', $body);
        self::assertIsArray($body['profile_photo']);
    }

    public function testProfilePhotoHasPublicUrlKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('publicUrl', $body['profile_photo']);
    }

    public function testProfilePhotoHasMimeTypeKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('mimeType', $body['profile_photo']);
        self::assertSame('image/jpeg', $body['profile_photo']['mimeType']);
    }

    public function testProfilePhotoHasFileSizeKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('fileSize', $body['profile_photo']);
        self::assertIsInt($body['profile_photo']['fileSize']);
    }

    public function testProfilePhotoHasMetadataKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('metadata', $body['profile_photo']);
        self::assertIsArray($body['profile_photo']['metadata']);
    }

    public function testProfilePhotoMetadataHasTitleKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('title', $body['profile_photo']['metadata']);
    }

    public function testProfilePhotoMetadataHasAlternativeKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('alternative', $body['profile_photo']['metadata']);
    }

    public function testProfilePhotoMetadataHasDescriptionKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('description', $body['profile_photo']['metadata']);
    }

    public function testProfilePhotoMetadataHasCopyrightKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('copyright', $body['profile_photo']['metadata']);
    }

    public function testProfilePhotoHasCropVariantsKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/70');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('cropVariants', $body['profile_photo']);
    }

    public function testProfilePhotoCropVariantsIsEmptyArrayWhenNoCropJson(): void
    {
        $response = $this->executeApiRequest('/_api/articles/70');
        $body     = $this->decodeResponseBody($response);

        // Fixture has no crop JSON → cropVariants should be []
        self::assertSame([], $body['profile_photo']['cropVariants']);
    }

    // ── profile_photo null when no file linked ────────────────────────────────

    public function testProfilePhotoIsNullWhenNoFileLinked(): void
    {
        $response = $this->executeApiRequest('/_api/articles/72');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('profile_photo', $body);
        self::assertNull($body['profile_photo']);
    }

    // ── downloads (hasMany, FileProcessor) ───────────────────────────────────

    public function testDownloadsIsArrayWhenFilesLinked(): void
    {
        $response = $this->executeApiRequest('/_api/articles/71');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('downloads', $body);
        self::assertIsArray($body['downloads']);
        self::assertCount(1, $body['downloads']);
    }

    public function testDownloadsItemHasPublicUrlKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/71');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('publicUrl', $body['downloads'][0]);
    }

    public function testDownloadsItemHasMimeTypeKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/71');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('mimeType', $body['downloads'][0]);
        self::assertSame('application/pdf', $body['downloads'][0]['mimeType']);
    }

    public function testDownloadsItemHasMetadataKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/71');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('metadata', $body['downloads'][0]);
        self::assertIsArray($body['downloads'][0]['metadata']);
    }

    public function testDownloadsItemDoesNotHaveCropVariantsKey(): void
    {
        $response = $this->executeApiRequest('/_api/articles/71');
        $body     = $this->decodeResponseBody($response);

        // FileProcessor (explicit on downloads column) has no cropVariants
        self::assertArrayNotHasKey('cropVariants', $body['downloads'][0]);
    }

    public function testDownloadsIsEmptyArrayWhenNoFilesLinked(): void
    {
        $response = $this->executeApiRequest('/_api/articles/72');
        $body     = $this->decodeResponseBody($response);

        self::assertArrayHasKey('downloads', $body);
        self::assertSame([], $body['downloads']);
    }

    // ── HTTP response ─────────────────────────────────────────────────────────

    public function testResponseIsOk(): void
    {
        $response = $this->executeApiRequest('/_api/articles/70');

        self::assertSame(200, $response->getStatusCode());
    }
}
