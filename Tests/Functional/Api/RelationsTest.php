<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for relation embedding (hasOne, manyToMany).
 *
 * Fixture data:
 *   Article 1 → color_id=1 (Red), categories=[1 (PHP), 2 (TYPO3)]
 *   Article 2 → color_id=2 (Blue), categories=[3 (API)]
 *   Article 3 → color_id=0 (none), categories=[] (none)
 */
final class RelationsTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_category_record_mm.csv');
    }

    // ── hasOne ──────────────────────────────────────────────────────────────

    public function testHasOneReturnsObjectNotScalar(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('color', $body);
        self::assertIsArray($body['color']);
    }

    public function testHasOneContainsAtId(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('@id', $body['color']);
        self::assertSame('/_api/colors/1', $body['color']['@id']);
    }

    public function testHasOneContainsAtType(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame('Color', $body['color']['@type']);
    }

    public function testHasOneContainsUid(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame(1, $body['color']['uid']);
    }

    public function testHasOneShallowEmbedDoesNotIncludeNameField(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayNotHasKey('name', $body['color']);
    }

    public function testHasOneWithZeroForeignKeyReturnsNull(): void
    {
        // Article 3 has color_id=0
        $response = $this->executeApiRequest('/_api/articles/3');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('color', $body);
        self::assertNull($body['color']);
    }

    // ── manyToMany (sys_category) ────────────────────────────────────────────

    public function testManyToManyReturnsArray(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('categories', $body);
        self::assertIsArray($body['categories']);
    }

    public function testManyToManyReturnsCorrectCount(): void
    {
        // Article 1 has 2 categories
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertCount(2, $body['categories']);
    }

    public function testManyToManyItemContainsAtId(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('@id', $body['categories'][0]);
        self::assertStringStartsWith('/_api/sys-categories/', $body['categories'][0]['@id']);
    }

    public function testManyToManyItemContainsAtType(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame('SysCategory', $body['categories'][0]['@type']);
    }

    public function testManyToManyItemContainsUid(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('uid', $body['categories'][0]);
    }

    public function testManyToManyWithNoRelationsReturnsEmptyArray(): void
    {
        // Article 3 has no categories
        $response = $this->executeApiRequest('/_api/articles/3');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('categories', $body);
        self::assertSame([], $body['categories']);
    }
}
