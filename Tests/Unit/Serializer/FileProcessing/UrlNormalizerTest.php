<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Serializer\FileProcessing;

use MaikSchneider\TcaApi\Serializer\FileProcessing\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string|null, 1: string|null}>
     */
    public static function urlProvider(): iterable
    {
        yield 'root-relative path gets a leading slash' => [
            'fileadmin/_processed_/c/foo.jpg',
            '/fileadmin/_processed_/c/foo.jpg',
        ];
        yield 'nested relative path gets a leading slash' => [
            'typo3temp/assets/images/bar.png',
            '/typo3temp/assets/images/bar.png',
        ];
        yield 'already root-relative is untouched' => [
            '/fileadmin/foo.jpg',
            '/fileadmin/foo.jpg',
        ];
        yield 'scheme-relative is untouched' => [
            '//cdn.example.com/foo.jpg',
            '//cdn.example.com/foo.jpg',
        ];
        yield 'http URL is untouched' => [
            'http://example.com/fileadmin/foo.jpg',
            'http://example.com/fileadmin/foo.jpg',
        ];
        yield 'https URL is untouched' => [
            'https://example.com/fileadmin/foo.jpg',
            'https://example.com/fileadmin/foo.jpg',
        ];
        yield 'null passes through' => [null, null];
        yield 'empty string passes through' => ['', ''];
    }

    #[Test]
    #[DataProvider('urlProvider')]
    public function toRootRelativeNormalisesAsExpected(?string $input, ?string $expected): void
    {
        self::assertSame($expected, UrlNormalizer::toRootRelative($input));
    }
}
