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
 * The validator reads allowed extensions from $GLOBALS['TCA'] and derives
 * MIME types via MimeTypeDetector. The test table 'tx_test_upload' is configured
 * in setUp() so each test can set up its expected extensions.
 *
 * MIME type detection is based on file magic bytes, not the client-provided
 * Content-Type header:
 *   - JPEG magic: "\xFF\xD8\xFF\xE0"
 *   - PDF  magic: "%PDF-"
 */
final class UploadValidatorTest extends TestCase
{
    private const TABLE  = 'tx_test_upload';
    private const COLUMN = 'file_field';

    private UploadValidator $validator;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->validator = new UploadValidator();

        // Provide the minimal TYPO3_CONF_VARS entries that FileInfo::getMimeType()
        // accesses when checking the fileExtensionToMimeType mapping.
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['FileInfo']['fileExtensionToMimeType'] ??= [];
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][\TYPO3\CMS\Core\Type\File\FileInfo::class]['mimeTypeGuessers'] ??= [];
        // GFX config used when expanding 'common-image-types'
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] ??= 'gif,jpg,jpeg,tif,tiff,bmp,pcx,tga,png,pdf,ai,svg,webp,avif';

        // Reset TCA for test table
        $GLOBALS['TCA'][self::TABLE]['columns'][self::COLUMN]['config'] = [
            'type'    => 'file',
            'allowed' => '',  // overridden per test via setTcaAllowed()
        ];
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

    private function setTcaAllowed(string $allowed): void
    {
        $GLOBALS['TCA'][self::TABLE]['columns'][self::COLUMN]['config']['allowed'] = $allowed;
    }

    private function makeFile(
        string $content,
        string $filename,
        string $clientMediaType = 'application/octet-stream',
        int $error = \UPLOAD_ERR_OK,
    ): UploadedFile {
        $tmpPath = tempnam(sys_get_temp_dir(), 'tca_api_test_') ?: sys_get_temp_dir() . '/tca_api_test';
        file_put_contents($tmpPath, $content);
        $this->tempFiles[] = $tmpPath;
        return new UploadedFile($tmpPath, \strlen($content), $error, $filename, $clientMediaType);
    }

    private function makeUpload(
        string $folder = '1:/uploads/',
        ?int $maxSize = null,
        string $duplication = 'rename',
    ): UploadDefinition {
        return new UploadDefinition(
            folder:      $folder,
            maxSize:     $maxSize,
            duplication: $duplication,
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

    private function validate(UploadedFile $file, UploadDefinition $upload): array
    {
        return $this->validator->validate($file, $upload, self::TABLE, self::COLUMN);
    }

    // ── Upload error ──────────────────────────────────────────────────────────

    public function testUploadErrorReturnsViolation(): void
    {
        $this->setTcaAllowed('');
        $file   = $this->makeFile('', 'broken.jpg', 'image/jpeg', \UPLOAD_ERR_NO_FILE);
        $upload = $this->makeUpload();

        $violations = $this->validate($file, $upload);

        self::assertCount(1, $violations);
        self::assertSame('UPLOAD_ERROR', $violations[0]['code']);
        self::assertSame(self::COLUMN, $violations[0]['propertyPath']);
    }

    public function testUploadErrorShortCircuitsOtherChecks(): void
    {
        // File has a disallowed MIME AND upload error — only UPLOAD_ERROR should appear.
        $this->setTcaAllowed('jpg,jpeg');
        $file   = $this->makeFile('', 'wrong.exe', 'application/exe', \UPLOAD_ERR_NO_FILE);
        $upload = $this->makeUpload();

        $violations = $this->validate($file, $upload);

        self::assertCount(1, $violations);
        self::assertSame('UPLOAD_ERROR', $violations[0]['code']);
    }

    // ── MIME type (from TCA allowed extensions) ───────────────────────────────

    public function testDisallowedMimeTypeReturnsViolation(): void
    {
        // TCA allows only JPEG/PNG → PDF magic bytes should be rejected
        $this->setTcaAllowed('jpg,jpeg,png');
        $file   = $this->makeFile($this->pdfContent(), 'doc.pdf', 'application/pdf');
        $upload = $this->makeUpload();

        $violations = $this->validate($file, $upload);

        $codes = array_column($violations, 'code');
        self::assertContains('UPLOAD_MIME_TYPE', $codes);
    }

    public function testAllowedMimeTypePassesValidation(): void
    {
        // TCA allows JPEG/PNG → JPEG magic bytes should pass
        $this->setTcaAllowed('jpg,jpeg,png');
        $file   = $this->makeFile($this->jpegContent(), 'photo.jpg', 'image/jpeg');
        $upload = $this->makeUpload();

        $violations = $this->validate($file, $upload);

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MIME_TYPE', $codes);
    }

    public function testEmptyTcaAllowedAcceptsAnything(): void
    {
        // No TCA restriction → no MIME or extension violation
        $this->setTcaAllowed('');
        $file   = $this->makeFile($this->pdfContent(), 'anything.pdf', 'application/pdf');
        $upload = $this->makeUpload();

        $violations = $this->validate($file, $upload);

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MIME_TYPE', $codes);
        self::assertNotContains('UPLOAD_EXTENSION', $codes);
    }

    public function testCommonImageTypesExpandsToGfxImagefileExt(): void
    {
        // 'common-image-types' must expand to GFX.imagefile_ext
        // We set imagefile_ext to only 'jpg,jpeg' so PDF should be rejected
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] = 'jpg,jpeg';
        $this->setTcaAllowed('common-image-types');
        $file   = $this->makeFile($this->pdfContent(), 'doc.pdf', 'application/pdf');
        $upload = $this->makeUpload();

        $violations = $this->validate($file, $upload);

        $codes = array_column($violations, 'code');
        self::assertContains('UPLOAD_MIME_TYPE', $codes);

        // Restore default
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] = 'gif,jpg,jpeg,tif,tiff,bmp,pcx,tga,png,pdf,ai,svg,webp,avif';
    }

    // ── File size ─────────────────────────────────────────────────────────────

    public function testFileSizeOverLimitReturnsViolation(): void
    {
        $this->setTcaAllowed('');
        $file   = $this->makeFile(str_repeat('x', 1_048_577), 'large.jpg', 'image/jpeg');
        $upload = $this->makeUpload(maxSize: 1_048_576);  // 1 MB limit

        $violations = $this->validate($file, $upload);

        $codes = array_column($violations, 'code');
        self::assertContains('UPLOAD_MAX_SIZE', $codes);
    }

    public function testFileSizeAtLimitPassesValidation(): void
    {
        $this->setTcaAllowed('');
        $file   = $this->makeFile(str_repeat('x', 1_048_576), 'exact.bin', 'application/octet-stream');
        $upload = $this->makeUpload(maxSize: 1_048_576);  // exactly 1 MB

        $violations = $this->validate($file, $upload);

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MAX_SIZE', $codes);
    }

    public function testNullMaxSizeAcceptsLargeFiles(): void
    {
        $this->setTcaAllowed('');
        $file   = $this->makeFile(str_repeat('x', 10_000), 'large.bin', 'application/octet-stream');
        $upload = $this->makeUpload(maxSize: null);

        $violations = $this->validate($file, $upload);

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MAX_SIZE', $codes);
    }

    // ── File extension (from TCA allowed) ─────────────────────────────────────

    public function testDisallowedExtensionReturnsViolation(): void
    {
        $this->setTcaAllowed('pdf,jpg');
        $file   = $this->makeFile($this->pdfContent(), 'document.exe', 'application/octet-stream');
        $upload = $this->makeUpload();

        $violations = $this->validate($file, $upload);

        $codes = array_column($violations, 'code');
        self::assertContains('UPLOAD_EXTENSION', $codes);
    }

    public function testAllowedExtensionPassesValidation(): void
    {
        $this->setTcaAllowed('pdf,jpg');
        $file   = $this->makeFile($this->pdfContent(), 'document.pdf', 'application/pdf');
        $upload = $this->makeUpload();

        $violations = $this->validate($file, $upload);

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_EXTENSION', $codes);
    }

    public function testEmptyTcaAllowedSkipsExtensionCheck(): void
    {
        $this->setTcaAllowed('');
        $file   = $this->makeFile($this->pdfContent(), 'document.exe', 'application/octet-stream');
        $upload = $this->makeUpload();

        $violations = $this->validate($file, $upload);

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_EXTENSION', $codes);
    }

    // ── Multiple violations ────────────────────────────────────────────────────

    public function testMimeAndSizeViolationsReturnedTogether(): void
    {
        // TCA allows only JPEG; also over size limit
        $this->setTcaAllowed('jpg,jpeg');
        $file   = $this->makeFile($this->pdfContent(2_000_000), 'bad.pdf', 'application/pdf');
        $upload = $this->makeUpload(maxSize: 1_000_000);

        $violations = $this->validate($file, $upload);

        $codes = array_column($violations, 'code');
        self::assertContains('UPLOAD_MIME_TYPE', $codes);
        self::assertContains('UPLOAD_MAX_SIZE', $codes);
    }

    // ── No violations ──────────────────────────────────────────────────────────

    public function testValidFileHasNoViolations(): void
    {
        $this->setTcaAllowed('jpg,jpeg,png');
        $file   = $this->makeFile($this->jpegContent(1_000), 'photo.jpg', 'image/jpeg');
        $upload = $this->makeUpload(maxSize: 5_242_880);

        $violations = $this->validate($file, $upload);

        self::assertSame([], $violations);
    }
}
