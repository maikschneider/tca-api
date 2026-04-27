<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Configuration;

use MaikSchneider\TcaApi\Configuration\ImageDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImageDefinitionTest extends TestCase
{
    // ── Empty config ────────────────────────────────────────────────────

    #[Test]
    public function emptyArrayCreatesDefaultDefinition(): void
    {
        $def = ImageDefinition::fromArray([]);

        self::assertNull($def->width);
        self::assertNull($def->height);
        self::assertNull($def->minWidth);
        self::assertNull($def->minHeight);
        self::assertNull($def->maxWidth);
        self::assertNull($def->maxHeight);
        self::assertNull($def->cropVariant);
        self::assertNull($def->fileExtension);
        self::assertFalse($def->absolute);
    }

    // ── width / height ───────────────────────────────────────────────────

    #[Test]
    public function plainIntegerWidthIsAccepted(): void
    {
        $def = ImageDefinition::fromArray(['width' => '400']);
        self::assertSame('400', $def->width);
    }

    #[Test]
    public function cropScaleWidthIsAccepted(): void
    {
        $def = ImageDefinition::fromArray(['width' => '400c']);
        self::assertSame('400c', $def->width);
    }

    #[Test]
    public function scaleDownOnlyWidthIsAccepted(): void
    {
        $def = ImageDefinition::fromArray(['width' => '400m']);
        self::assertSame('400m', $def->width);
    }

    #[Test]
    public function integerWidthIsCoercedToString(): void
    {
        $def = ImageDefinition::fromArray(['width' => 400]);
        self::assertSame('400', $def->width);
    }

    #[Test]
    public function invalidWidthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"width"');
        ImageDefinition::fromArray(['width' => 'full']);
    }

    #[Test]
    public function nonStringWidthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"width"');
        ImageDefinition::fromArray(['width' => ['400']]);
    }

    // ── minWidth / minHeight / maxWidth / maxHeight ──────────────────────

    #[Test]
    public function positiveIntDimensionsAreAccepted(): void
    {
        $def = ImageDefinition::fromArray([
            'minWidth'  => 100,
            'minHeight' => 50,
            'maxWidth'  => 1200,
            'maxHeight' => 800,
        ]);

        self::assertSame(100, $def->minWidth);
        self::assertSame(50, $def->minHeight);
        self::assertSame(1200, $def->maxWidth);
        self::assertSame(800, $def->maxHeight);
    }

    #[Test]
    public function zeroMaxWidthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"maxWidth"');
        ImageDefinition::fromArray(['maxWidth' => 0]);
    }

    #[Test]
    public function negativeMinHeightThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"minHeight"');
        ImageDefinition::fromArray(['minHeight' => -10]);
    }

    #[Test]
    public function stringMaxWidthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"maxWidth"');
        ImageDefinition::fromArray(['maxWidth' => '1200']);
    }

    // ── cropVariant ──────────────────────────────────────────────────────

    #[Test]
    public function cropVariantStringIsAccepted(): void
    {
        $def = ImageDefinition::fromArray(['cropVariant' => 'default']);
        self::assertSame('default', $def->cropVariant);
    }

    #[Test]
    public function emptyCropVariantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"cropVariant"');
        ImageDefinition::fromArray(['cropVariant' => '']);
    }

    #[Test]
    public function nonStringCropVariantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"cropVariant"');
        ImageDefinition::fromArray(['cropVariant' => 123]);
    }

    // ── fileExtension ─────────────────────────────────────────────────────

    #[Test]
    public function fileExtensionIsAccepted(): void
    {
        $def = ImageDefinition::fromArray(['fileExtension' => 'webp']);
        self::assertSame('webp', $def->fileExtension);
    }

    #[Test]
    public function emptyFileExtensionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"fileExtension"');
        ImageDefinition::fromArray(['fileExtension' => '']);
    }

    // ── absolute ─────────────────────────────────────────────────────────

    #[Test]
    public function absoluteTrueIsAccepted(): void
    {
        $def = ImageDefinition::fromArray(['absolute' => true]);
        self::assertTrue($def->absolute);
    }

    #[Test]
    public function absoluteDefaultsFalse(): void
    {
        $def = ImageDefinition::fromArray([]);
        self::assertFalse($def->absolute);
    }

    #[Test]
    public function nonBoolAbsoluteThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"absolute"');
        ImageDefinition::fromArray(['absolute' => 1]);
    }

    // ── Full config ──────────────────────────────────────────────────────

    #[Test]
    public function fullConfigIsAccepted(): void
    {
        $def = ImageDefinition::fromArray([
            'width'         => '800c',
            'height'        => '600',
            'minWidth'      => 200,
            'minHeight'     => 150,
            'maxWidth'      => 1600,
            'maxHeight'     => 1200,
            'cropVariant'   => 'mobile',
            'fileExtension' => 'webp',
            'absolute'      => true,
        ]);

        self::assertSame('800c', $def->width);
        self::assertSame('600', $def->height);
        self::assertSame(200, $def->minWidth);
        self::assertSame(150, $def->minHeight);
        self::assertSame(1600, $def->maxWidth);
        self::assertSame(1200, $def->maxHeight);
        self::assertSame('mobile', $def->cropVariant);
        self::assertSame('webp', $def->fileExtension);
        self::assertTrue($def->absolute);
    }
}
