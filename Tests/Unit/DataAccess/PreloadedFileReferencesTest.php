<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\DataAccess;

use MaikSchneider\TcaApi\DataAccess\PreloadedFileReferences;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\FileReference;

/**
 * Unit tests for PreloadedFileReferences::find().
 *
 * The distinction that matters is "covered with no references" (empty array —
 * the record genuinely has no files) versus "not covered" (null — the caller
 * must resolve the references itself).
 */
final class PreloadedFileReferencesTest extends TestCase
{
    #[Test]
    public function returnsReferencesForACoveredRecord(): void
    {
        $reference = $this->createMock(FileReference::class);
        $preloaded = new PreloadedFileReferences([1 => true], ['image' => [1 => [$reference]]]);

        self::assertSame([$reference], $preloaded->find('image', 1));
    }

    #[Test]
    public function returnsEmptyArrayForACoveredRecordWithoutReferences(): void
    {
        $preloaded = new PreloadedFileReferences([1 => true, 2 => true], ['image' => [1 => [$this->createMock(FileReference::class)]]]);

        self::assertSame([], $preloaded->find('image', 2));
    }

    #[Test]
    public function returnsNullForARecordOutsideThePreloadedPage(): void
    {
        $preloaded = new PreloadedFileReferences([1 => true], ['image' => [1 => []]]);

        self::assertNull($preloaded->find('image', 99));
    }

    #[Test]
    public function returnsNullForAColumnThatWasNotPreloaded(): void
    {
        $preloaded = new PreloadedFileReferences([1 => true], ['image' => [1 => []]]);

        self::assertNull($preloaded->find('downloads', 1));
    }

    #[Test]
    public function returnsNullWhenNothingWasPreloaded(): void
    {
        $preloaded = new PreloadedFileReferences([], []);

        self::assertNull($preloaded->find('image', 1));
    }
}
