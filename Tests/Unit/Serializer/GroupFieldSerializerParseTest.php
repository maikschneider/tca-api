<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Serializer;

use MaikSchneider\TcaApi\Serializer\GroupFieldSerializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the private parseMultiTableGroupValues() method extracted into
 * GroupFieldSerializer.  The method is tested via reflection because it contains
 * non-trivial parsing logic (strrpos split, zero-uid rejection, empty-string guard)
 * that warrants direct coverage beyond what the functional suite exercises.
 */
final class GroupFieldSerializerParseTest extends TestCase
{
    private \ReflectionMethod $parseMethod;

    protected function setUp(): void
    {
        $this->parseMethod = new \ReflectionMethod(GroupFieldSerializer::class, 'parseMultiTableGroupValues');
    }

    private function parse(string $raw): array
    {
        // newInstanceWithoutConstructor avoids TYPO3-dependent DataRepository/ApiRegistry.
        $instance = (new \ReflectionClass(GroupFieldSerializer::class))
            ->newInstanceWithoutConstructor();

        return $this->parseMethod->invoke($instance, $raw);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    #[Test]
    public function emptyStringReturnsEmptyArray(): void
    {
        self::assertSame([], $this->parse(''));
    }

    #[Test]
    public function singleSimpleEntry(): void
    {
        $result = $this->parse('pages_1');

        self::assertCount(1, $result);
        self::assertSame('pages', $result[0]['table']);
        self::assertSame(1, $result[0]['uid']);
    }

    #[Test]
    public function multipleEntriesPreserveOrder(): void
    {
        $result = $this->parse('pages_1,sys_file_3');

        self::assertCount(2, $result);
        self::assertSame('pages', $result[0]['table']);
        self::assertSame(1, $result[0]['uid']);
        self::assertSame('sys_file', $result[1]['table']);
        self::assertSame(3, $result[1]['uid']);
    }

    #[Test]
    public function tableNameWithUnderscoresUsesLastUnderscoreAsSplit(): void
    {
        // strrpos splits on the LAST '_', so tx_myext_domain_model_color_2 → table=tx_myext_domain_model_color, uid=2
        $result = $this->parse('tx_myext_domain_model_color_2');

        self::assertCount(1, $result);
        self::assertSame('tx_myext_domain_model_color', $result[0]['table']);
        self::assertSame(2, $result[0]['uid']);
    }

    #[Test]
    public function mixedTableNamesWithAndWithoutUnderscores(): void
    {
        $result = $this->parse('tx_myext_domain_model_article_201,tx_myext_domain_model_color_1');

        self::assertCount(2, $result);
        self::assertSame('tx_myext_domain_model_article', $result[0]['table']);
        self::assertSame(201, $result[0]['uid']);
        self::assertSame('tx_myext_domain_model_color', $result[1]['table']);
        self::assertSame(1, $result[1]['uid']);
    }

    // ── Edge cases / defensive behaviour ────────────────────────────────────

    #[Test]
    public function itemWithoutUnderscoreIsSkipped(): void
    {
        // No separator at all → strrpos returns false → entry skipped
        $result = $this->parse('nounderscore');

        self::assertSame([], $result);
    }

    #[Test]
    public function itemWithUidZeroIsSkipped(): void
    {
        // uid=0 is invalid; the guard `$uid > 0` drops it
        $result = $this->parse('pages_0');

        self::assertSame([], $result);
    }

    #[Test]
    public function itemWithNegativeUidIsSkipped(): void
    {
        // (int)'-5' = -5, which is not > 0
        $result = $this->parse('pages_-5');

        self::assertSame([], $result);
    }

    #[Test]
    public function whitespaceAroundEntriesIsTrimmed(): void
    {
        // GeneralUtility::trimExplode trims each entry
        $result = $this->parse(' pages_1 , sys_file_3 ');

        self::assertCount(2, $result);
        self::assertSame('pages', $result[0]['table']);
        self::assertSame('sys_file', $result[1]['table']);
    }

    #[Test]
    public function invalidItemsAreSkippedWhileValidOnesAreReturned(): void
    {
        $result = $this->parse('pages_1,nounderscore,pages_0,sys_file_3');

        self::assertCount(2, $result);
        self::assertSame(1, $result[0]['uid']);
        self::assertSame(3, $result[1]['uid']);
    }

    // ── Return type shape ─────────────────────────────────────────────────────

    #[Test]
    public function eachItemContainsTableAndUidKeys(): void
    {
        $result = $this->parse('pages_5');

        self::assertArrayHasKey('table', $result[0]);
        self::assertArrayHasKey('uid', $result[0]);
        self::assertIsString($result[0]['table']);
        self::assertIsInt($result[0]['uid']);
    }
}
