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

    // ── Article 1: hasOne + manyToMany relations present ───────────────────

    public function testArticleWithRelationsHasCorrectStructure(): void
    {
        $response = $this->executeApiRequest('/_api/articles/1');
        $body = $this->decodeResponseBody($response);

        // ── hasOne: color_id is a plain IRI string (non-embedded) ──────
        self::assertArrayHasKey('color_id', $body);
        self::assertIsString($body['color_id']);
        self::assertSame('/_api/colors/1', $body['color_id']);

        // ── manyToMany: categories is an array of embedded objects ──────
        self::assertArrayHasKey('categories', $body);
        self::assertIsArray($body['categories']);

        // manyToMany: correct count (Article 1 has 2 categories)
        self::assertCount(2, $body['categories']);

        // manyToMany: item contains @id
        self::assertArrayHasKey('@id', $body['categories'][0]);
        self::assertStringStartsWith('/_api/sys-categories/', $body['categories'][0]['@id']);

        // manyToMany: item contains @type
        self::assertSame('SysCategory', $body['categories'][0]['@type']);

        // manyToMany: item contains uid
        self::assertArrayHasKey('uid', $body['categories'][0]);
    }

    // ── Article 3: no relations ─────────────────────────────────────────────

    public function testArticleWithoutRelationsHasNullAndEmptyValues(): void
    {
        // Article 3 has color_id=0 and no categories
        $response = $this->executeApiRequest('/_api/articles/3');
        $body = $this->decodeResponseBody($response);

        // ── hasOne: zero foreign key returns null ───────────────────────
        self::assertArrayHasKey('color_id', $body);
        self::assertNull($body['color_id']);

        // ── manyToMany: no relations returns empty array ────────────────
        self::assertArrayHasKey('categories', $body);
        self::assertSame([], $body['categories']);
    }
}
