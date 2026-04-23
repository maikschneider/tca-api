<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Validation;

use MaikSchneider\TcaApi\Configuration\UploadDefinition;
use MaikSchneider\TcaApi\Validation\UploadValidator;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\UploadedFile;

/**
 * Unit tests for UploadValidator.
 *
 * TYPO3's built-in validators (MimeTypeValidator, FileSizeValidator) read from
 * the actual file on disk via finfo_file(), so each test case writes a temporary
 * file. Temp files are cleaned up in tearDown().
 *
 * MIME type detection is based on file magic bytes, not the client-provided
 * Content-Type header:
 *   - JPEG magic: "\xFF\xD8\xFF\xE0"
 *   - PDF  magic: "%PDF-"
 */
final class UploadValidatorTest extends TestCase
{
    private UploadValidator $validator;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->validator = new UploadValidator();

        // Provide the minimal TYPO3_CONF_VARS entries that FileInfo::getMimeType()
        // accesses when checking the fileExtensionToMimeType mapping. Without this
        // PHP emits an undefined-index warning inside the TYPO3 core.
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['FileInfo']['fileExtensionToMimeType'] ??= [];
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][\TYPO3\CMS\Core\Type\File\FileInfo::class]['mimeTypeGuessers'] ??= [];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeFile(
        string $content,
        string $filename,
        string $clientMediaType = 'application/octet-stream',
        int $error = \UPLOAD_ERR_OK,
    ): UploadedFile {
        $tmpPath = tempnam(sys_get_temp_dir(), 'tca_api_test_');
        file_put_contents($tmpPath, $content);
        $this->tempFiles[] = $tmpPath;
        return new UploadedFile($tmpPath, \strlen($content), $error, $filename, $clientMediaType);
    }

    private function makeUpload(
        string $folder = '1:/uploads/',
        array $allowed = [],
        ?int $maxSize = null,
        array $allowedExtensions = [],
    ): UploadDefinition {
        return new UploadDefinition(
            folder:            $folder,
            allowed:           $allowed,
            maxSize:           $maxSize,
            duplication:       'rename',
            allowedExtensions: $allowedExtensions,
        );
    }

    /** Returns JPEG magic bytes padded to $size bytes. */
    private function jpegContent(int $size = 100): string
    {
        return str_pad("\xFF\xD8\xFF\xE0", $size, "\x00");
    }

    /** Returns PDF magic bytes padded to $size bytes. */
    private function pdfContent(int $size = 100): string
    {
        return str_pad('%PDF-1.4', $size, "\x00");
    }

    // ── Upload error ──────────────────────────────────────────────────────────

    public function testUploadErrorReturnsViolation(): void
    {
        // UploadedFile requires a valid path; validator checks error code first and
        // returns without reading the file, so an empty temp file is sufficient.
        $file   = $this->makeFile('', 'broken.jpg', 'image/jpeg', \UPLOAD_ERR_NO_FILE);
        $upload = $this->makeUpload();

        $violations = $this->validator->validate($file, $upload, 'image');

        self::assertCount(1, $violations);
        self::assertSame('UPLOAD_ERROR', $violations[0]['code']);
        self::assertSame('image', $violations[0]['propertyPath']);
    }

    public function testUploadErrorShortCircuitsOtherChecks(): void
    {
        // File has a disallowed MIME AND upload error — only UPLOAD_ERROR should appear.
        $file   = $this->makeFile('', 'wrong.exe', 'application/exe', \UPLOAD_ERR_NO_FILE);
        $upload = $this->makeUpload(allowed: ['image/jpeg']);

        $violations = $this->validator->validate($file, $upload, 'image');

        self::assertCount(1, $violations);
        self::assertSame('UPLOAD_ERROR', $violations[0]['code']);
    }

    // ── MIME type ─────────────────────────────────────────────────────────────

    public function testDisallowedMimeTypeReturnsViolation(): void
    {
        // PDF magic bytes → finfo detects application/pdf → rejected by image/jpeg restriction
        $file   = $this->makeFile($this->pdfContent(), 'doc.pdf', 'application/pdf');
        $upload = $this->makeUpload(allowed: ['image/jpeg', 'image/png']);

        $violations = $this->validator->validate($file, $upload, 'photo');

        $codes = array_column($violations, 'code');
        self::assertContains('UPLOAD_MIME_TYPE', $codes);
    }

    public function testAllowedMimeTypePassesValidation(): void
    {
        // JPEG magic bytes → finfo detects image/jpeg → allowed
        $file   = $this->makeFile($this->jpegContent(), 'photo.jpg', 'image/jpeg');
        $upload = $this->makeUpload(allowed: ['image/jpeg', 'image/png']);

        $violations = $this->validator->validate($file, $upload, 'photo');

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MIME_TYPE', $codes);
    }

    public function testEmptyAllowedListAcceptsAnything(): void
    {
        $file   = $this->makeFile($this->pdfContent(), 'anything.pdf', 'application/pdf');
        $upload = $this->makeUpload(allowed: []);

        $violations = $this->validator->validate($file, $upload, 'file');

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MIME_TYPE', $codes);
    }

    // ── File size ─────────────────────────────────────────────────────────────

    public function testFileSizeOverLimitReturnsViolation(): void
    {
        $file   = $this->makeFile(str_repeat('x', 1_048_577), 'large.jpg', 'image/jpeg');
        $upload = $this->makeUpload(maxSize: 1_048_576);  // 1 MB limit

        $violations = $this->validator->validate($file, $upload, 'image');

        $codes = array_column($violations, 'code');
        self::assertContains('UPLOAD_MAX_SIZE', $codes);
    }

    public function testFileSizeAtLimitPassesValidation(): void
    {
        $file   = $this->makeFile(str_repeat('x', 1_048_576), 'exact.bin', 'application/octet-stream');
        $upload = $this->makeUpload(maxSize: 1_048_576);  // exactly 1 MB

        $violations = $this->validator->validate($file, $upload, 'image');

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MAX_SIZE', $codes);
    }

    public function testNullMaxSizeAcceptsLargeFiles(): void
    {
        $file   = $this->makeFile(str_repeat('x', 10_000), 'large.bin', 'application/octet-stream');
        $upload = $this->makeUpload(maxSize: null);

        $violations = $this->validator->validate($file, $upload, 'image');

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MAX_SIZE', $codes);
    }

    // ── File extension ────────────────────────────────────────────────────────

    public function testDisallowedExtensionReturnsViolation(): void
    {
        $file   = $this->makeFile($this->pdfContent(), 'document.exe', 'application/octet-stream');
        $upload = $this->makeUpload(allowedExtensions: ['pdf', 'jpg']);

        $violations = $this->validator->validate($file, $upload, 'file');

        $codes = array_column($violations, 'code');
        self::assertContains('UPLOAD_EXTENSION', $codes);
    }

    public function testAllowedExtensionPassesValidation(): void
    {
        $file   = $this->makeFile($this->pdfContent(), 'document.pdf', 'application/pdf');
        $upload = $this->makeUpload(allowedExtensions: ['pdf', 'jpg']);

        $violations = $this->validator->validate($file, $upload, 'file');

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_EXTENSION', $codes);
    }

    public function testEmptyAllowedExtensionsSkipsExtensionCheck(): void
    {
        $file   = $this->makeFile($this->pdfContent(), 'document.exe', 'application/octet-stream');
        $upload = $this->makeUpload(allowedExtensions: []);

        $violations = $this->validator->validate($file, $upload, 'file');

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_EXTENSION', $codes);
    }

    // ── Multiple violations ────────────────────────────────────────────────────

    public function testMimeAndSizeViolationsReturnedTogether(): void
    {
        // PDF content → wrong MIME; also over the size limit
        $file   = $this->makeFile($this->pdfContent(2_000_000), 'bad.pdf', 'application/pdf');
        $upload = $this->makeUpload(allowed: ['image/jpeg'], maxSize: 1_000_000);

        $violations = $this->validator->validate($file, $upload, 'file');

        $codes = array_column($violations, 'code');
        self::assertContains('UPLOAD_MIME_TYPE', $codes);
        self::assertContains('UPLOAD_MAX_SIZE', $codes);
    }

    // ── No violations ──────────────────────────────────────────────────────────

    public function testValidFileHasNoViolations(): void
    {
        $file   = $this->makeFile($this->jpegContent(1_000), 'photo.jpg', 'image/jpeg');
        $upload = $this->makeUpload(
            allowed:           ['image/jpeg'],
            maxSize:           5_242_880,
            allowedExtensions: ['jpg', 'jpeg'],
        );

        $violations = $this->validator->validate($file, $upload, 'photo');

        self::assertSame([], $violations);
    }
}
