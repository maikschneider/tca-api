<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Configuration;

use MaikSchneider\TcaApi\Configuration\UploadDefinition;
use PHPUnit\Framework\TestCase;

final class UploadDefinitionTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    private function makeTempFile(string $content = 'test content'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tca_api_mask_test_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function makeUpload(?string $filenameMask = null): UploadDefinition
    {
        return new UploadDefinition(
            folder:       '1:/uploads/',
            maxSize:      null,
            duplication:  'rename',
            filenameMask: $filenameMask,
        );
    }

    // ── applyMask: null mask returns original ───────────────────────────────

    public function testNullMaskReturnsOriginalFilename(): void
    {
        $upload = $this->makeUpload(null);
        $path   = $this->makeTempFile();

        self::assertSame('photo.jpg', $upload->applyMask('photo.jpg', $path));
    }

    // ── applyMask: static string (no placeholders) ─────────────────────────

    public function testStaticMaskReturnsLiteralString(): void
    {
        $upload = $this->makeUpload('fixed-name.png');
        $path   = $this->makeTempFile();

        self::assertSame('fixed-name.png', $upload->applyMask('photo.jpg', $path));
    }

    // ── applyMask: {name} placeholder ───────────────────────────────────────

    public function testNamePlaceholderReplacesWithFilenameWithoutExtension(): void
    {
        $upload = $this->makeUpload('{name}.png');
        $path   = $this->makeTempFile();

        self::assertSame('photo.png', $upload->applyMask('photo.jpg', $path));
    }

    // ── applyMask: {extension} placeholder ──────────────────────────────────

    public function testExtensionPlaceholderReplacesWithExtensionWithoutDot(): void
    {
        $upload = $this->makeUpload('file.{extension}');
        $path   = $this->makeTempFile();

        self::assertSame('file.jpg', $upload->applyMask('photo.jpg', $path));
    }

    // ── applyMask: {ext} placeholder ────────────────────────────────────────

    public function testExtPlaceholderReplacesWithDottedExtension(): void
    {
        $upload = $this->makeUpload('file{ext}');
        $path   = $this->makeTempFile();

        self::assertSame('file.jpg', $upload->applyMask('photo.jpg', $path));
    }

    public function testExtPlaceholderIsEmptyWhenNoExtension(): void
    {
        $upload = $this->makeUpload('file{ext}');
        $path   = $this->makeTempFile();

        self::assertSame('file', $upload->applyMask('README', $path));
    }

    // ── applyMask: {contentHash} placeholder ────────────────────────────────

    public function testContentHashPlaceholderReplacesWithMd5OfFileContent(): void
    {
        $content = 'deterministic content for hashing';
        $path    = $this->makeTempFile($content);
        $upload  = $this->makeUpload('{contentHash}{ext}');

        $expected = md5($content) . '.jpg';
        self::assertSame($expected, $upload->applyMask('photo.jpg', $path));
    }

    // ── applyMask: {nameHash} placeholder ───────────────────────────────────

    public function testNameHashPlaceholderReplacesWithMd5OfFilenameWithoutExtension(): void
    {
        $upload = $this->makeUpload('{nameHash}{ext}');
        $path   = $this->makeTempFile();

        $expected = md5('photo') . '.jpg';
        self::assertSame($expected, $upload->applyMask('photo.jpg', $path));
    }

    // ── applyMask: {timestamp} placeholder ──────────────────────────────────

    public function testTimestampPlaceholderReplacesWithUnixTimestamp(): void
    {
        $upload = $this->makeUpload('{timestamp}{ext}');
        $path   = $this->makeTempFile();

        $before = time();
        $result = $upload->applyMask('photo.jpg', $path);
        $after  = time();

        // Extract numeric part before ".jpg"
        $ts = (int)str_replace('.jpg', '', $result);
        self::assertGreaterThanOrEqual($before, $ts);
        self::assertLessThanOrEqual($after, $ts);
    }

    // ── applyMask: {unique} placeholder ─────────────────────────────────────

    public function testUniquePlaceholderProducesNonEmptyValue(): void
    {
        $upload = $this->makeUpload('{unique}{ext}');
        $path   = $this->makeTempFile();

        $result = $upload->applyMask('photo.jpg', $path);

        // Must end with .jpg and have content before it
        self::assertStringEndsWith('.jpg', $result);
        self::assertGreaterThan(4, strlen($result)); // more than just ".jpg"
    }

    public function testUniquePlaceholderProducesDifferentValuesEachCall(): void
    {
        $upload = $this->makeUpload('{unique}{ext}');
        $path   = $this->makeTempFile();

        $result1 = $upload->applyMask('photo.jpg', $path);
        $result2 = $upload->applyMask('photo.jpg', $path);

        self::assertNotSame($result1, $result2);
    }

    // ── applyMask: combined placeholders ────────────────────────────────────

    public function testCombinedPlaceholdersAreAllResolved(): void
    {
        $content = 'hash me';
        $path    = $this->makeTempFile($content);
        $upload  = $this->makeUpload('prefix-{nameHash}-{contentHash}{ext}');

        $result = $upload->applyMask('document.pdf', $path);

        $expected = 'prefix-' . md5('document') . '-' . md5($content) . '.pdf';
        self::assertSame($expected, $result);
    }

    // ── fromArray: filenameMask parsing ──────────────────────────────────────

    public function testFromArrayParsesFilenameMask(): void
    {
        $def = UploadDefinition::fromArray([
            'folder'       => '1:/uploads/',
            'filenameMask' => '{contentHash}{ext}',
        ]);

        self::assertSame('{contentHash}{ext}', $def->filenameMask);
    }

    public function testFromArrayDefaultsFilenameMaskToNull(): void
    {
        $def = UploadDefinition::fromArray([
            'folder' => '1:/uploads/',
        ]);

        self::assertNull($def->filenameMask);
    }

    public function testFromArrayRejectsEmptyStringFilenameMask(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filenameMask');

        UploadDefinition::fromArray([
            'folder'       => '1:/uploads/',
            'filenameMask' => '',
        ]);
    }

    public function testFromArrayRejectsNonStringFilenameMask(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        UploadDefinition::fromArray([
            'folder'       => '1:/uploads/',
            'filenameMask' => 42,
        ]);
    }
}
