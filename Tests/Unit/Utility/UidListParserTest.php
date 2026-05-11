<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Utility;

use MaikSchneider\TcaApi\Utility\UidListParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UidListParserTest extends TestCase
{
    #[Test]
    public function mapToRowsPreservesOrderOfUids(): void
    {
        $indexed = [
            1 => ['uid' => 1, 'title' => 'One'],
            2 => ['uid' => 2, 'title' => 'Two'],
            3 => ['uid' => 3, 'title' => 'Three'],
        ];

        $result = UidListParser::mapToRows([3, 1, 2], $indexed);

        self::assertCount(3, $result);
        self::assertSame('Three', $result[0]['title']);
        self::assertSame('One', $result[1]['title']);
        self::assertSame('Two', $result[2]['title']);
    }

    #[Test]
    public function mapToRowsFiltersOutMissingUids(): void
    {
        $indexed = [
            1 => ['uid' => 1, 'title' => 'One'],
        ];

        $result = UidListParser::mapToRows([1, 99, 42], $indexed);

        self::assertCount(1, $result);
        self::assertSame('One', $result[0]['title']);
    }

    #[Test]
    public function mapToRowsReturnsEmptyArrayForEmptyUids(): void
    {
        $result = UidListParser::mapToRows([], [1 => ['uid' => 1]]);

        self::assertSame([], $result);
    }

    #[Test]
    public function mapToRowsReturnsEmptyArrayWhenIndexedIsEmpty(): void
    {
        $result = UidListParser::mapToRows([1, 2, 3], []);

        self::assertSame([], $result);
    }

    #[Test]
    public function mapToRowsReIndexesResultToSequentialKeys(): void
    {
        $indexed = [
            5 => ['uid' => 5],
            6 => ['uid' => 6],
        ];

        $result = UidListParser::mapToRows([6, 5], $indexed);

        self::assertArrayHasKey(0, $result);
        self::assertArrayHasKey(1, $result);
        self::assertSame(6, $result[0]['uid']);
        self::assertSame(5, $result[1]['uid']);
    }
}
