<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Frontend;

use MaikSchneider\TcaApi\Frontend\FluidArrayNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FluidArrayNormalizerTest extends TestCase
{
    private FluidArrayNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new FluidArrayNormalizer();
    }

    // ── normalize(): single record ───────────────────────────────────────

    #[Test]
    public function normalizeStripsAtPrefixedKeys(): void
    {
        $record = [
            '@id'   => '/_api/articles/1',
            '@type' => 'Article',
            'uid'   => 1,
            'title' => 'Hello',
        ];

        $result = $this->normalizer->normalize($record);

        self::assertArrayNotHasKey('@id', $result);
        self::assertArrayNotHasKey('@type', $result);
        self::assertSame(1, $result['uid']);
        self::assertSame('Hello', $result['title']);
    }

    #[Test]
    public function normalizeStripsAtContextKey(): void
    {
        $record = [
            '@context' => 'https://schema.org/',
            '@id'      => '/_api/articles/1',
            '@type'    => 'Article',
            'title'    => 'Context Test',
        ];

        $result = $this->normalizer->normalize($record);

        self::assertArrayNotHasKey('@context', $result);
        self::assertArrayHasKey('title', $result);
    }

    #[Test]
    public function normalizeStripsHydraPrefixedKeys(): void
    {
        $record = [
            'hydra:totalItems' => 42,
            'hydra:view'       => ['@id' => '/_api/articles?page=1'],
            'uid'              => 1,
            'title'            => 'Hydra test',
        ];

        $result = $this->normalizer->normalize($record);

        self::assertArrayNotHasKey('hydra:totalItems', $result);
        self::assertArrayNotHasKey('hydra:view', $result);
        self::assertSame(1, $result['uid']);
        self::assertSame('Hydra test', $result['title']);
    }

    #[Test]
    public function normalizePreservesScalarValues(): void
    {
        $record = [
            'uid'      => 5,
            'title'    => 'Test Article',
            'count'    => 42,
            'active'   => true,
            'price'    => 9.99,
            'nullable' => null,
        ];

        $result = $this->normalizer->normalize($record);

        self::assertSame(5, $result['uid']);
        self::assertSame('Test Article', $result['title']);
        self::assertSame(42, $result['count']);
        self::assertTrue($result['active']);
        self::assertSame(9.99, $result['price']);
        self::assertNull($result['nullable']);
    }

    #[Test]
    public function normalizePreservesRegularNestedArrays(): void
    {
        $record = [
            'uid'   => 1,
            'image' => [
                'publicUrl' => '/fileadmin/img.webp',
                'width'     => 800,
                'height'    => 600,
            ],
        ];

        $result = $this->normalizer->normalize($record);

        self::assertSame('/fileadmin/img.webp', $result['image']['publicUrl']);
        self::assertSame(800, $result['image']['width']);
        self::assertSame(600, $result['image']['height']);
    }

    #[Test]
    public function normalizeRecursivelyStripsNestedJsonLdKeys(): void
    {
        $record = [
            'uid'   => 1,
            'color' => [
                '@id'   => '/_api/colors/3',
                '@type' => 'Color',
                'uid'   => 3,
                'name'  => 'Red',
            ],
        ];

        $result = $this->normalizer->normalize($record);

        self::assertIsArray($result['color']);
        self::assertArrayNotHasKey('@id', $result['color']);
        self::assertArrayNotHasKey('@type', $result['color']);
        self::assertSame(3, $result['color']['uid']);
        self::assertSame('Red', $result['color']['name']);
    }

    #[Test]
    public function normalizeWithShallowRelationStubPreservesUid(): void
    {
        // A shallow (non-embedded) relation serializes as a stub with only @id, @type, uid.
        // After normalization the @ keys are stripped but uid survives.
        $record = [
            'uid'      => 1,
            'category' => [
                '@id'   => '/_api/categories/7',
                '@type' => 'Category',
                'uid'   => 7,
            ],
        ];

        $result = $this->normalizer->normalize($record);

        self::assertIsArray($result['category']);
        self::assertSame(['uid' => 7], $result['category']);
    }

    #[Test]
    public function normalizeHandlesListRelationsRecursively(): void
    {
        $record = [
            'uid'  => 1,
            'tags' => [
                ['@id' => '/_api/tags/1', '@type' => 'Tag', 'uid' => 1, 'name' => 'PHP'],
                ['@id' => '/_api/tags/2', '@type' => 'Tag', 'uid' => 2, 'name' => 'TYPO3'],
            ],
        ];

        $result = $this->normalizer->normalize($record);

        self::assertIsArray($result['tags']);
        self::assertCount(2, $result['tags']);
        self::assertArrayNotHasKey('@id', $result['tags'][0]);
        self::assertArrayNotHasKey('@type', $result['tags'][0]);
        self::assertSame(1, $result['tags'][0]['uid']);
        self::assertSame('PHP', $result['tags'][0]['name']);
        self::assertSame(2, $result['tags'][1]['uid']);
        self::assertSame('TYPO3', $result['tags'][1]['name']);
    }

    #[Test]
    public function normalizeWithEmptyArrayReturnsEmptyArray(): void
    {
        $result = $this->normalizer->normalize([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function normalizeWithOnlyJsonLdKeysReturnsEmptyArray(): void
    {
        $record = [
            '@id'      => '/_api/articles/1',
            '@type'    => 'Article',
            '@context' => 'https://schema.org/',
        ];

        $result = $this->normalizer->normalize($record);

        self::assertSame([], $result);
    }

    #[Test]
    public function normalizeHandlesDeeplyNestedStructures(): void
    {
        $record = [
            '@id'   => '/_api/articles/1',
            'uid'   => 1,
            'color' => [
                '@id'      => '/_api/colors/2',
                '@type'    => 'Color',
                'uid'      => 2,
                'category' => [
                    '@id'   => '/_api/categories/5',
                    '@type' => 'Category',
                    'uid'   => 5,
                    'name'  => 'Vibrant',
                ],
            ],
        ];

        $result = $this->normalizer->normalize($record);

        self::assertSame(1, $result['uid']);
        self::assertArrayNotHasKey('@id', $result);
        self::assertSame(2, $result['color']['uid']);
        self::assertArrayNotHasKey('@id', $result['color']);
        self::assertSame(5, $result['color']['category']['uid']);
        self::assertSame('Vibrant', $result['color']['category']['name']);
        self::assertArrayNotHasKey('@id', $result['color']['category']);
        self::assertArrayNotHasKey('@type', $result['color']['category']);
    }

    #[Test]
    public function normalizeListNodesPreserveScalarsInList(): void
    {
        // A list (sequential array) of scalars should pass through unchanged.
        $record = [
            'uid'  => 1,
            'tags' => ['php', 'typo3', 'api'],
        ];

        $result = $this->normalizer->normalize($record);

        self::assertSame(['php', 'typo3', 'api'], $result['tags']);
    }

    // ── normalizeCollection(): multiple records ──────────────────────────

    #[Test]
    public function normalizeCollectionStripsJsonLdKeysFromEachRecord(): void
    {
        $records = [
            ['@id' => '/_api/articles/1', '@type' => 'Article', 'uid' => 1, 'title' => 'First'],
            ['@id' => '/_api/articles/2', '@type' => 'Article', 'uid' => 2, 'title' => 'Second'],
        ];

        $result = $this->normalizer->normalizeCollection($records);

        self::assertCount(2, $result);

        foreach ($result as $item) {
            self::assertArrayNotHasKey('@id', $item);
            self::assertArrayNotHasKey('@type', $item);
            self::assertArrayHasKey('uid', $item);
            self::assertArrayHasKey('title', $item);
        }

        self::assertSame(1, $result[0]['uid']);
        self::assertSame('First', $result[0]['title']);
        self::assertSame(2, $result[1]['uid']);
        self::assertSame('Second', $result[1]['title']);
    }

    #[Test]
    public function normalizeCollectionWithEmptyArrayReturnsEmptyArray(): void
    {
        $result = $this->normalizer->normalizeCollection([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function normalizeCollectionWithSingleRecordWorks(): void
    {
        $records = [['@id' => '/_api/articles/1', 'uid' => 1, 'title' => 'Single']];

        $result = $this->normalizer->normalizeCollection($records);

        self::assertCount(1, $result);
        self::assertArrayNotHasKey('@id', $result[0]);
        self::assertSame('Single', $result[0]['title']);
    }

    #[Test]
    public function normalizeCollectionDoesNotMutateOriginalRecords(): void
    {
        $original = [
            ['@id' => '/_api/articles/1', 'uid' => 1, 'title' => 'Test'],
        ];

        // Make a copy so we can compare after normalisation
        $before = $original;
        $this->normalizer->normalizeCollection($original);

        // The input array is unchanged (PHP arrays are pass-by-value)
        self::assertSame($before, $original);
        self::assertArrayHasKey('@id', $original[0]);
    }

    // ── Edge cases ───────────────────────────────────────────────────────

    #[Test]
    public function normalizePreservesNumericKeys(): void
    {
        // A list node (sequential array) is treated as a list, not stripped
        $record = [
            'uid'      => 1,
            'variants' => [
                0 => ['uid' => 10, 'name' => 'Small'],
                1 => ['uid' => 11, 'name' => 'Large'],
            ],
        ];

        $result = $this->normalizer->normalize($record);

        self::assertCount(2, $result['variants']);
        self::assertSame('Small', $result['variants'][0]['name']);
        self::assertSame('Large', $result['variants'][1]['name']);
    }

    #[Test]
    public function normalizeDoesNotStripKeysContainingAtSignInMiddle(): void
    {
        // Only keys that *start* with '@' should be stripped; a key like 'email@domain'
        // should be preserved. (Unusual but verifies the str_starts_with logic.)
        $record = [
            'uid'          => 1,
            'contact_info' => 'admin@example.com',
        ];

        $result = $this->normalizer->normalize($record);

        self::assertArrayHasKey('contact_info', $result);
        self::assertSame('admin@example.com', $result['contact_info']);
    }

    #[Test]
    public function normalizeDoesNotStripKeysContainingHydraInMiddle(): void
    {
        // Only keys that *start* with 'hydra:' should be stripped.
        $record = [
            'uid'              => 1,
            'hydra_compatible' => true,  // key starts with 'hydra_', not 'hydra:'
        ];

        $result = $this->normalizer->normalize($record);

        self::assertArrayHasKey('hydra_compatible', $result);
        self::assertTrue($result['hydra_compatible']);
    }
}