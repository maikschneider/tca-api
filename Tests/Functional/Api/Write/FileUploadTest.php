<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Write;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;

/**
 * Functional tests for the file upload feature.
 *
 * Covers:
 * - Access control (FE_USER required for upload)
 * - Missing file error (400)
 * - MIME type validation (422)
 * - File size validation (422)
 * - File extension validation (422)
 * - Successful upload response structure (201)
 * - Owner tracking via tx_tcaapi_owner in sys_file_metadata
 * - Location header on successful upload
 * - Upload accepted under resource name field key
 * - Metadata fields written on upload
 */
final class FileUploadTest extends ApiFunctionalTestCase
{
    private const RESOURCE_NAME = 'test-uploads';

    /**
     * A minimal 1×1 transparent PNG (67 bytes).
     * Detected by finfo_buffer as image/png.
     */
    private const MINIMAL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');

        $this->setUpFileStorage();
        $this->registerFileUploadResource();
    }

    /**
     * Create the FAL local storage (uid=1) pointing to fileadmin/ and ensure
     * the upload target directory exists on the file system.
     */
    private function setUpFileStorage(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('sys_file_storage')
            ->insert('sys_file_storage', [
                'uid'                  => 1,
                'pid'                  => 0,
                'name'                 => 'Fileadmin',
                'description'          => '',
                'driver'               => 'Local',
                'configuration'        => serialize(['pathType' => 'relative', 'basePath' => 'fileadmin/']),
                'is_default'           => 1,
                'is_browsable'         => 1,
                'is_public'            => 1,
                'is_writable'          => 1,
                'is_online'            => 1,
                'auto_extract_metadata' => 0,
                'deleted'              => 0,
                'hidden'               => 0,
            ]);

        GeneralUtility::mkdir_deep(
            Environment::getPublicPath() . '/fileadmin/test_uploads/',
        );
    }

    // ── Resource registration helpers ─────────────────────────────────────────

    /**
     * Register (or re-register) the upload test resource.
     * $overrides is deep-merged on top of the base configuration.
     */
    private function registerFileUploadResource(array $overrides = []): void
    {
        ApiRegistry::register(self::RESOURCE_NAME, array_replace_recursive(
            [
                'general' => [
                    'table'        => 'sys_file',
                    'resourceName' => self::RESOURCE_NAME,
                    'resourceType' => 'FileResource',
                    'type'         => 'file_upload',
                    'operations'   => ['upload'],
                ],
                'upload' => [
                    'uploadFolder'     => '1:/test_uploads/',
                    'allowedMimeTypes' => ['image/jpeg', 'image/png'],
                    'maxFileSize'      => '5M',
                    'allowedExtensions' => ['jpg', 'jpeg', 'png'],
                ],
                'metadata' => [
                    'allowedFields' => ['title', 'description', 'alternative'],
                ],
                'ownership' => ['enabled' => true],
                'security'  => ['upload' => AccessRole::FE_USER],
            ],
            $overrides,
        ));
    }

    // ── File creation helpers ──────────────────────────────────────────────────

    /**
     * Build an UploadedFile backed by a stream with the given content.
     */
    private function createUploadedFile(
        string $content,
        string $clientFilename,
        string $clientMimeType,
    ): UploadedFile {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($content);
        $stream->rewind();

        return new UploadedFile($stream, strlen($content), UPLOAD_ERR_OK, $clientFilename, $clientMimeType);
    }

    /** Minimal 1×1 PNG UploadedFile — passes image/png MIME check. */
    private function createMinimalPngUploadedFile(string $clientFilename = 'test.png'): UploadedFile
    {
        $decoded = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($decoded, 'Failed to base64-decode the minimal PNG constant.');

        return $this->createUploadedFile($decoded, $clientFilename, 'image/png');
    }

    /** Plain-text UploadedFile — detected as text/plain by finfo. */
    private function createTextUploadedFile(string $clientFilename = 'test.txt'): UploadedFile
    {
        return $this->createUploadedFile('Hello World', $clientFilename, 'text/plain');
    }

    // ── Request dispatch helpers ───────────────────────────────────────────────

    /**
     * Execute a POST upload request as an authenticated FE user.
     *
     * @param array<string, UploadedFile> $uploadedFiles
     */
    private function executeUploadAs(int $feUserId, array $uploadedFiles): ResponseInterface
    {
        $request = (new InternalRequest('http://localhost/_api/' . self::RESOURCE_NAME))
            ->withMethod('POST')
            ->withUploadedFiles($uploadedFiles);

        return $this->executeFrontendSubRequest(
            $request,
            (new InternalRequestContext())->withFrontendUserId($feUserId),
        );
    }

    /**
     * Execute a POST upload request without any authentication.
     *
     * @param array<string, UploadedFile> $uploadedFiles
     */
    private function executeUploadAnonymous(array $uploadedFiles = []): ResponseInterface
    {
        $request = (new InternalRequest('http://localhost/_api/' . self::RESOURCE_NAME))
            ->withMethod('POST')
            ->withUploadedFiles($uploadedFiles);

        return $this->executeFrontendSubRequest($request);
    }

    // ── Access control ─────────────────────────────────────────────────────────

    public function testUploadWithoutAuthenticationReturns403(): void
    {
        $response = $this->executeUploadAnonymous();

        self::assertSame(403, $response->getStatusCode());
    }

    public function testGetRequestOnUploadResourceReturns405(): void
    {
        $response = $this->executeApiRequest('/_api/' . self::RESOURCE_NAME);

        self::assertSame(405, $response->getStatusCode());
    }

    // ── Missing / erroneous file ────────────────────────────────────────────────

    public function testUploadWithNoFileReturns400(): void
    {
        $request = (new InternalRequest('http://localhost/_api/' . self::RESOURCE_NAME))
            ->withMethod('POST');

        $response = $this->executeFrontendSubRequest(
            $request,
            (new InternalRequestContext())->withFrontendUserId(1),
        );
        $body = $this->decodeResponseBody($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('No file uploaded', $body['hydra:description']);
    }

    public function testUploadWithFileErrorReturns400(): void
    {
        $stream = new Stream('php://temp', 'rw');
        $brokenFile = new UploadedFile($stream, 0, UPLOAD_ERR_NO_FILE, 'none.png', 'image/png');

        $response = $this->executeUploadAs(1, ['file' => $brokenFile]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('File upload error', $body['hydra:description']);
    }

    // ── MIME type validation ─────────────────────────────────────────────────────

    public function testUploadWithInvalidMimeTypeReturns422(): void
    {
        // "Hello World" → finfo detects text/plain, config requires image/jpeg|png
        $response = $this->executeUploadAs(1, ['file' => $this->createTextUploadedFile()]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        $codes = array_column($body['violations'], 'code');
        self::assertContains('MIME_TYPE', $codes);
        self::assertSame('file', $body['violations'][0]['propertyPath']);
    }

    // ── File size validation ─────────────────────────────────────────────────────

    public function testUploadWithFileTooLargeReturns422(): void
    {
        // Re-register with a 1-byte maximum so any real file is too large
        $this->registerFileUploadResource([
            'upload' => ['maxFileSize' => '1B'],
        ]);

        $response = $this->executeUploadAs(1, ['file' => $this->createMinimalPngUploadedFile()]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        $codes = array_column($body['violations'], 'code');
        self::assertContains('FILE_SIZE', $codes);
    }

    // ── Extension validation ──────────────────────────────────────────────────────

    public function testUploadWithInvalidExtensionReturns422(): void
    {
        // Register with PNG MIME allowed but only jpg extension accepted.
        // Use direct registration to avoid array_replace_recursive merging sequential arrays.
        ApiRegistry::register(self::RESOURCE_NAME, [
            'general' => [
                'table'        => 'sys_file',
                'resourceName' => self::RESOURCE_NAME,
                'resourceType' => 'FileResource',
                'type'         => 'file_upload',
                'operations'   => ['upload'],
            ],
            'upload' => [
                'uploadFolder'      => '1:/test_uploads/',
                'allowedMimeTypes'  => ['image/png'],
                'allowedExtensions' => ['jpg'],
            ],
            'security' => ['upload' => AccessRole::FE_USER],
        ]);

        // Valid PNG content passes MIME check; .png extension is not in ['jpg']
        $response = $this->executeUploadAs(1, ['file' => $this->createMinimalPngUploadedFile('test.png')]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(422, $response->getStatusCode());
        $codes = array_column($body['violations'], 'code');
        self::assertContains('FILE_EXTENSION', $codes);
    }

    // ── Successful upload ─────────────────────────────────────────────────────────

    public function testSuccessfulUploadReturns201(): void
    {
        $response = $this->executeUploadAs(1, ['file' => $this->createMinimalPngUploadedFile()]);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testUploadResponseHasCorrectStructure(): void
    {
        $response = $this->executeUploadAs(1, ['file' => $this->createMinimalPngUploadedFile()]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertArrayHasKey('@context', $body);
        self::assertArrayHasKey('@type', $body);
        self::assertArrayHasKey('@id', $body);
        self::assertArrayHasKey('uid', $body);
        self::assertArrayHasKey('publicUrl', $body);
        self::assertArrayHasKey('fileName', $body);
        self::assertArrayHasKey('mimeType', $body);
        self::assertArrayHasKey('fileSize', $body);
        self::assertArrayHasKey('metadata', $body);
        self::assertSame('FileResource', $body['@type']);
        self::assertIsInt($body['uid']);
        self::assertGreaterThan(0, $body['uid']);
    }

    public function testUploadResponseHasLocationHeader(): void
    {
        $response = $this->executeUploadAs(1, ['file' => $this->createMinimalPngUploadedFile()]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($response->hasHeader('Location'));
        self::assertSame(
            '/_api/' . self::RESOURCE_NAME . '/' . $body['uid'],
            $response->getHeaderLine('Location'),
        );
    }

    // ── Owner tracking ─────────────────────────────────────────────────────────────

    public function testUploadSetsOwnerForAuthenticatedFeUser(): void
    {
        $response = $this->executeUploadAs(1, ['file' => $this->createMinimalPngUploadedFile()]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $body['metadata']['tx_tcaapi_owner']);
    }

    public function testUploadDoesNotSetOwnerWhenOwnershipDisabled(): void
    {
        $this->registerFileUploadResource(['ownership' => ['enabled' => false]]);

        $response = $this->executeUploadAs(1, ['file' => $this->createMinimalPngUploadedFile()]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(0, $body['metadata']['tx_tcaapi_owner']);
    }

    // ── Metadata fields ─────────────────────────────────────────────────────────────

    public function testUploadMetadataFieldsAreIncludedInResponse(): void
    {
        $response = $this->executeUploadAs(1, ['file' => $this->createMinimalPngUploadedFile()]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        $metadata = $body['metadata'];
        self::assertArrayHasKey('title', $metadata);
        self::assertArrayHasKey('description', $metadata);
        self::assertArrayHasKey('alternative', $metadata);
        self::assertArrayHasKey('tx_tcaapi_owner', $metadata);
    }

    // ── Resource name field key ─────────────────────────────────────────────────────

    public function testUploadAcceptsFileUnderResourceNameKey(): void
    {
        // Handler looks for $uploadedFiles[$resourceName] before 'file'
        $response = $this->executeUploadAs(1, [
            self::RESOURCE_NAME => $this->createMinimalPngUploadedFile(),
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    // ── Response fields values ─────────────────────────────────────────────────────

    public function testUploadedFileHasCorrectMimeTypeInResponse(): void
    {
        $response = $this->executeUploadAs(1, ['file' => $this->createMinimalPngUploadedFile('photo.png')]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('image/png', $body['mimeType']);
    }

    public function testUploadedFileHasCorrectFileNameInResponse(): void
    {
        $response = $this->executeUploadAs(1, ['file' => $this->createMinimalPngUploadedFile('my-photo.png')]);
        $body     = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('my-photo.png', $body['fileName']);
    }
}
