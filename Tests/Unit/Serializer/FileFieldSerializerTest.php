<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Serializer;

use MaikSchneider\TcaApi\Serializer\FileFieldSerializer;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;
use MaikSchneider\TcaApi\Serializer\FileProcessing\ImageProcessor;
use MaikSchneider\TcaApi\Serializer\Processing\ProcessorGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\FileRepository;

/**
 * Unit tests for FileFieldSerializer::detectProcessorClass().
 *
 * The method is private; accessed via ReflectionMethod.
 * GFX/imagefile_ext is set directly on $GLOBALS before each test.
 */
final class FileFieldSerializerTest extends TestCase
{
    private FileFieldSerializer $serializer;
    private \ReflectionMethod $detect;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] = 'gif,jpg,jpeg,tif,tiff,bmp,pcx,tga,png,pdf,ai,svg,webp,avif';

        $this->serializer = new FileFieldSerializer(
            $this->createMock(FileRepository::class),
            new ProcessorGuard($this->createMock(LoggerInterface::class)),
        );

        $this->detect = new \ReflectionMethod(FileFieldSerializer::class, 'detectProcessorClass');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']);
    }

    private function detect(array $extensions): string
    {
        return $this->detect->invoke($this->serializer, $extensions);
    }

    // ── empty / wildcard ─────────────────────────────────────────────────────

    #[Test]
    public function emptyAllowedReturnsFileProcessor(): void
    {
        self::assertSame(FileProcessor::class, $this->detect([]));
    }

    #[Test]
    public function wildcardReturnsFileProcessor(): void
    {
        self::assertSame(FileProcessor::class, $this->detect(['*']));
    }

    #[Test]
    public function wildcardMixedWithImagesReturnsFileProcessor(): void
    {
        self::assertSame(FileProcessor::class, $this->detect(['jpg', '*', 'png']));
    }

    // ── all-image subsets ────────────────────────────────────────────────────

    #[Test]
    public function singleImageExtensionReturnsImageProcessor(): void
    {
        self::assertSame(ImageProcessor::class, $this->detect(['jpg']));
    }

    #[Test]
    public function typicalImageSubsetReturnsImageProcessor(): void
    {
        self::assertSame(ImageProcessor::class, $this->detect(['jpg', 'jpeg', 'png', 'gif', 'webp']));
    }

    #[Test]
    public function allImageExtensionsReturnsImageProcessor(): void
    {
        $all = ['gif', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'pcx', 'tga', 'png', 'pdf', 'ai', 'svg', 'webp', 'avif'];
        self::assertSame(ImageProcessor::class, $this->detect($all));
    }

    // ── non-image extensions ──────────────────────────────────────────────────

    #[Test]
    public function singleNonImageExtensionReturnsFileProcessor(): void
    {
        self::assertSame(FileProcessor::class, $this->detect(['csv']));
    }

    #[Test]
    public function mixedImageAndNonImageReturnsFileProcessor(): void
    {
        // pdf is in imagefile_ext, but csv is not
        self::assertSame(FileProcessor::class, $this->detect(['pdf', 'csv', 'xlsx', 'docx']));
    }

    #[Test]
    public function documentExtensionsReturnFileProcessor(): void
    {
        self::assertSame(FileProcessor::class, $this->detect(['pdf', 'csv', 'xlsx', 'docx']));
    }

    // ── case-insensitive matching ─────────────────────────────────────────────

    #[Test]
    public function upperCaseExtensionsMatchCaseInsensitively(): void
    {
        self::assertSame(ImageProcessor::class, $this->detect(['JPG', 'PNG', 'WEBP']));
    }

    #[Test]
    public function mixedCaseExtensionsMatchCaseInsensitively(): void
    {
        self::assertSame(ImageProcessor::class, $this->detect(['Jpg', 'Png']));
    }

    // ── missing GFX config ────────────────────────────────────────────────────

    #[Test]
    public function missingGfxConfigReturnsFileProcessorForAnyExtension(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']);

        // With empty imagefile_ext, no extension can be a subset → FileProcessor
        self::assertSame(FileProcessor::class, $this->detect(['jpg']));
    }
}
