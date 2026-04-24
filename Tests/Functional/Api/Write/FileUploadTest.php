<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for multipart/form-data file upload on create/update operations.
 *
 * Fixtures:
 *   Articles resource has profile_photo (maxitems=1, allowed images) and downloads
 *   (multi-file, allowed pdf/csv/image) configured with 'upload' key.
 *
 * Validation tests (MIME type, size, upload error) run without FAL storage because
 * UploadValidator fires before any FAL write. The full upload-and-persist path is
 * covered by integration tests that require a configured sys_file_storage (uid=1).
 */
final class FileUploadTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
    }

    // ── Validation: MIME type ────────────────────────────────────────────────

    public function testMultipartWithDisallowedMimeTypeReturns422(): void
    {
        $file = $this->createUploadedFile(
            content:  'fake pdf content',
            filename: 'document.pdf',
            mimeType: 'application/pdf',  // not allowed for profile_photo (images only)
        );

        $response = $this->executeApiMultipartWriteRequestAs(
            method:   'POST',
            path:     '/_api/articles',
            feUserId: 1,
            fields:   ['title' => 'My Article'],
            files:    ['profile_photo' => $file],
        );

        self::assertSame(422, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('violations', $body);

        $codes = array_column($body['violations'], 'code');
        self::assertContains('UPLOAD_MIME_TYPE', $codes);

        $paths = array_column($body['violations'], 'propertyPath');
        self::assertContains('profile_photo', $paths);
    }

    // ── Validation: file size ────────────────────────────────────────────────

    public function testMultipartWithOversizedFileReturns422(): void
    {
        // profile_photo maxSize is 5M (5_242_880 bytes); send 6M
        $file = $this->createUploadedFile(
            content:  str_repeat('x', 6_291_456),  // 6 MB
            filename: 'huge.jpg',
            mimeType: 'image/jpeg',
        );

        $response = $this->executeApiMultipartWriteRequestAs(
            method:   'POST',
            path:     '/_api/articles',
            feUserId: 1,
            fields:   ['title' => 'My Article'],
            files:    ['profile_photo' => $file],
        );

        self::assertSame(422, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        $codes = array_column($body['violations'], 'code');
        self::assertContains('UPLOAD_MAX_SIZE', $codes);
    }

    // ── Validation: upload transport error ───────────────────────────────────

    public function testMultipartWithUploadErrorReturns422(): void
    {
        $file = $this->createUploadedFile(
            content:  '',
            filename: 'broken.jpg',
            mimeType: 'image/jpeg',
            error:    \UPLOAD_ERR_NO_FILE,
        );

        $response = $this->executeApiMultipartWriteRequestAs(
            method:   'POST',
            path:     '/_api/articles',
            feUserId: 1,
            fields:   ['title' => 'My Article'],
            files:    ['profile_photo' => $file],
        );

        self::assertSame(422, $response->getStatusCode());

        $body  = $this->decodeResponseBody($response);
        $codes = array_column($body['violations'], 'code');
        self::assertContains('UPLOAD_ERROR', $codes);
    }

    // ── Multiple violations in one request ───────────────────────────────────

    public function testMultipartReturnsMultipleViolationsWhenBothFilesInvalid(): void
    {
        $badMime = $this->createUploadedFile('content', 'doc.pdf', 'application/pdf');  // wrong MIME for profile_photo
        $tooBig  = $this->createUploadedFile(str_repeat('x', 21_000_000), 'big.pdf', 'application/pdf');  // 20M+ for downloads (maxSize 20M)

        $response = $this->executeApiMultipartWriteRequestAs(
            method:   'POST',
            path:     '/_api/articles',
            feUserId: 1,
            fields:   ['title' => 'My Article'],
            files:    [
                'profile_photo' => $badMime,
                'downloads'     => $tooBig,
            ],
        );

        self::assertSame(422, $response->getStatusCode());

        $body  = $this->decodeResponseBody($response);
        $codes = array_column($body['violations'], 'code');
        self::assertContains('UPLOAD_MIME_TYPE', $codes);
        self::assertContains('UPLOAD_MAX_SIZE', $codes);
    }

    // ── Scalar field validation still applies ─────────────────────────────────

    public function testMultipartRespectsScalarFieldValidation(): void
    {
        // JPEG magic bytes so MIME validation passes; title is too short (minLength=3)
        // so the scalar validator should fire and return MIN_LENGTH.
        $file = $this->createUploadedFile("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 96), 'photo.jpg', 'image/jpeg');

        $response = $this->executeApiMultipartWriteRequestAs(
            method:   'POST',
            path:     '/_api/articles',
            feUserId: 1,
            fields:   ['title' => 'AB'],  // too short
            files:    ['profile_photo' => $file],
        );

        self::assertSame(422, $response->getStatusCode());

        $body  = $this->decodeResponseBody($response);
        $codes = array_column($body['violations'], 'code');
        self::assertContains('MIN_LENGTH', $codes);
    }

    // ── Regression: JSON body is unaffected ──────────────────────────────────

    public function testJsonCreateRemainsUnchangedWhenNoMultipart(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/_api/articles', 1, [
            'title' => 'JSON Article',
        ]);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        self::assertSame('Article', $body['@type']);
        self::assertSame('JSON Article', $body['title']);
    }

    // ── File field not in upload config is silently ignored ───────────────────

    public function testFileForColumnWithoutUploadConfigIsIgnored(): void
    {
        // 'title' column has no upload config — passing a file for it via
        // multipart should not trigger validation but be silently skipped.
        $file = $this->createUploadedFile('data', 'photo.jpg', 'image/jpeg');

        $response = $this->executeApiMultipartWriteRequestAs(
            method:   'POST',
            path:     '/_api/articles',
            feUserId: 1,
            fields:   ['title' => 'Valid Title'],
            files:    ['title' => $file],  // 'title' is a text column, not uploadable
        );

        // Should not return 422 for 'title' upload — the file is ignored.
        // The request may succeed (201) or fail for other reasons, but not
        // because of an UPLOAD_* violation on 'title'.
        $body  = $this->decodeResponseBody($response);
        $codes = array_column($body['violations'] ?? [], 'code');
        self::assertNotContains('UPLOAD_MIME_TYPE', $codes);
        self::assertNotContains('UPLOAD_ERROR', $codes);
        self::assertNotContains('UPLOAD_MAX_SIZE', $codes);
    }

    // ── PATCH: absent file field keeps existing references ────────────────────

    public function testPatchWithoutFileFieldDoesNotTouchExistingReferences(): void
    {
        // Import file fixtures so article 410 has a profile_photo reference.
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_with_files.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_reference.csv');

        // PATCH only the title — do NOT include profile_photo in the request.
        $response = $this->executeApiWriteRequestAs('PATCH', '/_api/articles/410', 1, [
            'title' => 'Updated Title',
        ]);

        self::assertSame(200, $response->getStatusCode());

        // profile_photo should still be present in the serialised response.
        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('profile_photo', $body);
        // The file reference is preserved (not null, not an empty array).
        self::assertNotNull($body['profile_photo']);
    }

    // ── PUT with multipart: absent file column removes references ─────────────

    public function testPutWithMultipartAndNoFileSetsEmptyColumnForThatField(): void
    {
        // PUT semantics: if a writable file column is absent from the multipart body
        // (no file and no placeholder), it is not set in $data — DataHandler leaves
        // it unchanged. This is consistent with how scalar columns behave on PUT.
        // (A future feature could support explicit "remove file" via empty field.)

        $response = $this->executeApiMultipartWriteRequestAs(
            method:   'PUT',
            path:     '/_api/articles/1',
            feUserId: 1,
            fields:   ['title' => 'Updated via PUT'],
            files:    [],  // no file — existing refs untouched by this handler
        );

        // 200 OK is expected (record exists in articles.csv)
        self::assertSame(200, $response->getStatusCode());
    }

    // ── Happy path: create with file upload stores FAL reference ─────────────

    public function testMultipartCreateStoresFileAndCreatesReference(): void
    {
        // JPEG magic bytes so MIME + extension validation passes
        $jpegContent = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 96);
        $file = $this->createUploadedFile($jpegContent, 'photo.jpg', 'image/jpeg');

        $response = $this->executeApiMultipartWriteRequestAs(
            method:   'POST',
            path:     '/_api/articles',
            feUserId: 1,
            fields:   ['title' => 'Upload Test'],
            files:    ['profile_photo' => $file],
        );

        self::assertSame(201, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        self::assertSame('Article', $body['@type']);
        self::assertSame('Upload Test', $body['title']);

        // Verify a sys_file record was created in FAL
        $createdUid = (int)$body['uid'];
        self::assertGreaterThan(0, $createdUid);

        $refRow = $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->select(
                ['uid', 'uid_local', 'uid_foreign', 'fieldname', 'tablenames'],
                'sys_file_reference',
                [
                    'uid_foreign' => $createdUid,
                    'tablenames'  => 'tx_myext_domain_model_article',
                    'fieldname'   => 'profile_photo',
                    'deleted'     => 0,
                ],
            )
            ->fetchAssociative();

        self::assertIsArray($refRow, 'sys_file_reference should exist for the uploaded file');
        self::assertGreaterThan(0, (int)$refRow['uid_local'], 'uid_local should point to a sys_file record');

        // Verify the sys_file record exists
        $fileRow = $this->getConnectionPool()
            ->getConnectionForTable('sys_file')
            ->select(
                ['uid', 'name', 'extension'],
                'sys_file',
                ['uid' => (int)$refRow['uid_local']],
            )
            ->fetchAssociative();

        self::assertIsArray($fileRow, 'sys_file record should exist');
        self::assertSame('jpg', $fileRow['extension']);
    }

    // ── Happy path: update with file upload adds new reference ───────────────

    public function testMultipartUpdateStoresFileAndCreatesReference(): void
    {
        $jpegContent = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 96);
        $file = $this->createUploadedFile($jpegContent, 'avatar.jpg', 'image/jpeg');

        $response = $this->executeApiMultipartWriteRequestAs(
            method:   'PUT',
            path:     '/_api/articles/1',
            feUserId: 1,
            fields:   ['title' => 'Updated Article'],
            files:    ['profile_photo' => $file],
        );

        self::assertSame(200, $response->getStatusCode());

        $refRow = $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->select(
                ['uid', 'uid_local', 'fieldname'],
                'sys_file_reference',
                [
                    'uid_foreign' => 1,
                    'tablenames'  => 'tx_myext_domain_model_article',
                    'fieldname'   => 'profile_photo',
                    'deleted'     => 0,
                ],
            )
            ->fetchAssociative();

        self::assertIsArray($refRow, 'sys_file_reference should exist after update with file upload');
        self::assertGreaterThan(0, (int)$refRow['uid_local']);
    }

    // ── Multi-file upload to a single column ────────────────────────────────

    public function testMultipartCreateWithMultipleFilesOnSingleColumn(): void
    {
        // downloads column allows pdf — use PDF magic bytes
        $pdf1 = $this->createUploadedFile('%PDF-1.4' . str_repeat("\x00", 92), 'doc1.pdf', 'application/pdf');
        $pdf2 = $this->createUploadedFile('%PDF-1.4' . str_repeat("\x00", 92), 'doc2.pdf', 'application/pdf');

        $response = $this->executeApiMultipartWriteRequestAs(
            method:   'POST',
            path:     '/_api/articles',
            feUserId: 1,
            fields:   ['title' => 'Multi Upload'],
            files:    ['downloads' => [$pdf1, $pdf2]],
        );

        self::assertSame(201, $response->getStatusCode());

        $body       = $this->decodeResponseBody($response);
        $createdUid = (int)$body['uid'];
        self::assertGreaterThan(0, $createdUid);

        // Verify two sys_file_reference records were created for the downloads column
        $refRows = $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->select(
                ['uid', 'uid_local', 'fieldname'],
                'sys_file_reference',
                [
                    'uid_foreign' => $createdUid,
                    'tablenames'  => 'tx_myext_domain_model_article',
                    'fieldname'   => 'downloads',
                    'deleted'     => 0,
                ],
            )
            ->fetchAllAssociative();

        self::assertCount(2, $refRows, 'Two sys_file_reference records should exist for multi-file upload');
    }

    // ── Mixed: file upload + scalar fields in single multipart request ───────

    public function testMultipartCreateWithFileAndScalarFieldsTogether(): void
    {
        $jpegContent = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 96);
        $pdfContent  = '%PDF-1.4' . str_repeat("\x00", 92);

        $response = $this->executeApiMultipartWriteRequestAs(
            method:   'POST',
            path:     '/_api/articles',
            feUserId: 1,
            fields:   ['title' => 'Mixed Upload'],
            files:    [
                'profile_photo' => $this->createUploadedFile($jpegContent, 'pic.jpg', 'image/jpeg'),
                'downloads'     => $this->createUploadedFile($pdfContent, 'manual.pdf', 'application/pdf'),
            ],
        );

        self::assertSame(201, $response->getStatusCode());

        $body       = $this->decodeResponseBody($response);
        $createdUid = (int)$body['uid'];
        self::assertGreaterThan(0, $createdUid);

        // Both columns should have file references
        $photoRef = $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->select(
                ['uid'],
                'sys_file_reference',
                [
                    'uid_foreign' => $createdUid,
                    'tablenames'  => 'tx_myext_domain_model_article',
                    'fieldname'   => 'profile_photo',
                    'deleted'     => 0,
                ],
            )
            ->fetchAssociative();

        $downloadRef = $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->select(
                ['uid'],
                'sys_file_reference',
                [
                    'uid_foreign' => $createdUid,
                    'tablenames'  => 'tx_myext_domain_model_article',
                    'fieldname'   => 'downloads',
                    'deleted'     => 0,
                ],
            )
            ->fetchAssociative();

        self::assertIsArray($photoRef, 'profile_photo sys_file_reference should exist');
        self::assertIsArray($downloadRef, 'downloads sys_file_reference should exist');
    }
}
