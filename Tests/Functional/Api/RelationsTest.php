<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for relation embedding (hasOne, manyToMany).
 *
 * RED phase: ResourceSerializer does not yet handle relation types.
 * All tests must fail until relation serialization is implemented.
 *
 * Fixture data:
 *   Article 1 → category_id=1 (Tech), tags=[1 (php), 2 (typo3)]
 *   Article 2 → category_id=2 (Science), tags=[3 (api)]
 *   Article 3 → category_id=0 (none), tags=[] (none)
 */
final class RelationsTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tags.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/article_tag_mm.csv');
    }

    // ── hasOne ──────────────────────────────────────────────────────────────

    public function testHasOneReturnsObjectNotScalar(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('category', $body);
        self::assertIsArray($body['category']);
    }

    public function testHasOneContainsAtId(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('@id', $body['category']);
        self::assertSame('/_api/categories/1', $body['category']['@id']);
    }

    public function testHasOneContainsAtType(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame('Category', $body['category']['@type']);
    }

    public function testHasOneContainsUid(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['category']['uid']);
    }

    public function testHasOneShallowEmbedDoesNotIncludeNameField(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayNotHasKey('name', $body['category']);
    }

    public function testHasOneWithZeroForeignKeyReturnsNull(): void
    {
        // Article 3 has category_id=0
        $response = $this->executeApiRequest('/_api/articles/3');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('category', $body);
        self::assertNull($body['category']);
    }

    // ── manyToMany ───────────────────────────────────────────────────────────

    public function testManyToManyReturnsArray(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('tags', $body);
        self::assertIsArray($body['tags']);
    }

    public function testManyToManyReturnsCorrectCount(): void
    {
        // Article 1 has 2 tags
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertCount(2, $body['tags']);
    }

    public function testManyToManyItemContainsAtId(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('@id', $body['tags'][0]);
        self::assertStringStartsWith('/_api/tags/', $body['tags'][0]['@id']);
    }

    public function testManyToManyItemContainsAtType(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame('Tag', $body['tags'][0]['@type']);
    }

    public function testManyToManyItemContainsUid(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('uid', $body['tags'][0]);
    }

    public function testManyToManyWithNoRelationsReturnsEmptyArray(): void
    {
        // Article 3 has no tags
        $response = $this->executeApiRequest('/_api/articles/3');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('tags', $body);
        self::assertSame([], $body['tags']);
    }
}
