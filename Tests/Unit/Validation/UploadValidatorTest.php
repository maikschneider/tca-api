<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Validation;

use MaikSchneider\TcaApi\Configuration\UploadDefinition;
use MaikSchneider\TcaApi\Validation\UploadValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\UploadedFile;

final class UploadValidatorTest extends TestCase
{
    private UploadValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UploadValidator();
    }

    private function makeFile(
        string $content,
        string $filename,
        string $mimeType,
        int $error = \UPLOAD_ERR_OK,
    ): UploadedFile {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($content);
        $stream->rewind();
        return new UploadedFile($stream, \strlen($content), $error, $filename, $mimeType);
    }

    private function makeUpload(
        string $folder = '1:/uploads/',
        array $allowed = [],
        ?int $maxSize = null,
    ): UploadDefinition {
        return new UploadDefinition(
            folder:      $folder,
            allowed:     $allowed,
            maxSize:     $maxSize,
            duplication: 'rename',
        );
    }

    // ── Upload error ────────────────────────────────────────────────────────

    public function testUploadErrorReturnsViolation(): void
    {
        $file   = $this->makeFile('', 'broken.jpg', 'image/jpeg', \UPLOAD_ERR_NO_FILE);
        $upload = $this->makeUpload();

        $violations = $this->validator->validate($file, $upload, 'image');

        self::assertCount(1, $violations);
        self::assertSame('UPLOAD_ERROR', $violations[0]['code']);
        self::assertSame('image', $violations[0]['propertyPath']);
    }

    public function testUploadErrorShortCircuitsOtherChecks(): void
    {
        // File has the wrong MIME type AND upload error — only UPLOAD_ERROR should appear.
        $file   = $this->makeFile('', 'wrong.exe', 'application/exe', \UPLOAD_ERR_NO_FILE);
        $upload = $this->makeUpload(allowed: ['image/jpeg']);

        $violations = $this->validator->validate($file, $upload, 'image');

        self::assertCount(1, $violations);
        self::assertSame('UPLOAD_ERROR', $violations[0]['code']);
    }

    // ── MIME type ───────────────────────────────────────────────────────────

    public function testDisallowedMimeTypeReturnsViolation(): void
    {
        $file   = $this->makeFile('data', 'doc.pdf', 'application/pdf');
        $upload = $this->makeUpload(allowed: ['image/jpeg', 'image/png']);

        $violations = $this->validator->validate($file, $upload, 'photo');

        $codes = array_column($violations, 'code');
        self::assertContains('UPLOAD_MIME_TYPE', $codes);
    }

    public function testAllowedMimeTypePassesValidation(): void
    {
        $file   = $this->makeFile('data', 'photo.jpg', 'image/jpeg');
        $upload = $this->makeUpload(allowed: ['image/jpeg', 'image/png']);

        $violations = $this->validator->validate($file, $upload, 'photo');

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MIME_TYPE', $codes);
    }

    #[DataProvider('wildcardMimeProvider')]
    public function testWildcardMimeTypeMatching(string $allowed, string $actual, bool $expectPass): void
    {
        $file   = $this->makeFile('data', 'file.bin', $actual);
        $upload = $this->makeUpload(allowed: [$allowed]);

        $violations = $this->validator->validate($file, $upload, 'file');

        $codes = array_column($violations, 'code');
        if ($expectPass) {
            self::assertNotContains('UPLOAD_MIME_TYPE', $codes);
        } else {
            self::assertContains('UPLOAD_MIME_TYPE', $codes);
        }
    }

    public static function wildcardMimeProvider(): array
    {
        return [
            'image/* matches image/jpeg'      => ['image/*', 'image/jpeg', true],
            'image/* matches image/png'       => ['image/*', 'image/png', true],
            'image/* matches image/webp'      => ['image/*', 'image/webp', true],
            'image/* rejects application/pdf' => ['image/*', 'application/pdf', false],
            'image/* rejects text/plain'      => ['image/*', 'text/plain', false],
            'exact match works'               => ['application/pdf', 'application/pdf', true],
            'exact match rejects other'       => ['application/pdf', 'application/json', false],
        ];
    }

    public function testEmptyAllowedListAcceptsAnything(): void
    {
        $file   = $this->makeFile('data', 'anything.bin', 'application/octet-stream');
        $upload = $this->makeUpload(allowed: []);

        $violations = $this->validator->validate($file, $upload, 'file');

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MIME_TYPE', $codes);
    }

    public function testMimeMatchingIsCaseInsensitive(): void
    {
        $file   = $this->makeFile('data', 'photo.jpg', 'IMAGE/JPEG');
        $upload = $this->makeUpload(allowed: ['image/jpeg']);

        $violations = $this->validator->validate($file, $upload, 'photo');

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MIME_TYPE', $codes);
    }

    // ── File size ───────────────────────────────────────────────────────────

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
        $file   = $this->makeFile(str_repeat('x', 1_048_576), 'exact.jpg', 'image/jpeg');
        $upload = $this->makeUpload(maxSize: 1_048_576);  // exactly 1 MB

        $violations = $this->validator->validate($file, $upload, 'image');

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MAX_SIZE', $codes);
    }

    public function testNullMaxSizeAcceptsLargeFiles(): void
    {
        $file   = $this->makeFile(str_repeat('x', 100_000_000), 'huge.jpg', 'image/jpeg');
        $upload = $this->makeUpload(maxSize: null);

        $violations = $this->validator->validate($file, $upload, 'image');

        $codes = array_column($violations, 'code');
        self::assertNotContains('UPLOAD_MAX_SIZE', $codes);
    }

    // ── Multiple violations ──────────────────────────────────────────────────

    public function testBothMimeAndSizeViolationsReturnedTogether(): void
    {
        $file   = $this->makeFile(str_repeat('x', 2_000_000), 'bad.exe', 'application/exe');
        $upload = $this->makeUpload(allowed: ['image/jpeg'], maxSize: 1_000_000);

        $violations = $this->validator->validate($file, $upload, 'file');

        $codes = array_column($violations, 'code');
        self::assertContains('UPLOAD_MIME_TYPE', $codes);
        self::assertContains('UPLOAD_MAX_SIZE', $codes);
    }

    // ── No violations ────────────────────────────────────────────────────────

    public function testValidFileHasNoViolations(): void
    {
        $file   = $this->makeFile(str_repeat('x', 1_000), 'photo.jpg', 'image/jpeg');
        $upload = $this->makeUpload(allowed: ['image/jpeg'], maxSize: 5_242_880);

        $violations = $this->validator->validate($file, $upload, 'photo');

        self::assertSame([], $violations);
    }
}
