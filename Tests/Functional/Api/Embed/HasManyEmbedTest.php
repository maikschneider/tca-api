<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for hasMany relation embedding via 'embed' column config.
 *
 * Without 'embed', hasMany relations return shallow stubs [{@id, @type, uid}] (unchanged).
 * With 'embed' => true, each related record is serialized in full at depth 1.
 * With 'embed' => ['depth' => N], embedding respects the depth budget.
 *
 * Fixture data (from articles.csv + sys_categories.csv + sys_category_record_mm.csv):
 *   Article 1 → categories [1 (PHP), 2 (TYPO3)]
 *   Article 2 → categories [3 (API)]
 *   Article 3 → categories [] (none)
 */
final class HasManyEmbedTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE  = 'tx_myext_domain_model_article';
    private const CATEGORY_TABLE = 'sys_category';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm.csv');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function registerCategoryResource(): void
    {
        $this->registerResource('hm-categories', [
            'general' => [
                'table'        => self::CATEGORY_TABLE,
                'resourceName' => 'hm-categories',
                'resourceType' => 'Category',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerArticleResource(array $columnOverrides = []): void
    {
        $this->registerResource('hm-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'hm-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => array_merge([
                'title'      => ['groups' => ['list', 'show']],
                'categories' => ['groups' => ['list', 'show']],
            ], $columnOverrides),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Without embed: IRI strings ────────────────────────────────────────────

    public function testHasManyWithoutEmbedReturnsIriStrings(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/hm-articles/1');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['categories']);
        self::assertCount(2, $body['categories']);
        self::assertIsString($body['categories'][0]);
        self::assertStringContainsString('/_api/', $body['categories'][0]);
        self::assertMatchesRegularExpression('#/_api/[^/]+/\d+$#', $body['categories'][0]);
        foreach ($body['categories'] as $iri) {
            self::assertIsString($iri);
        }
    }

    // ── With embed: full records ───────────────────────────────────────────────

    public function testHasManyWithEmbedReturnsFullRecordsWithJsonLdFields(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource([
            'categories' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/hm-articles/1');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['categories']);
        self::assertCount(2, $body['categories']);

        $titles = array_column($body['categories'], 'title');
        self::assertContains('PHP', $titles);
        self::assertContains('TYPO3', $titles);

        $cat = $body['categories'][0];
        self::assertArrayHasKey('@id', $cat);
        self::assertArrayHasKey('@type', $cat);
        self::assertArrayHasKey('uid', $cat);
        self::assertSame('SysCategory', $cat['@type']);
        self::assertStringStartsWith('/_api/', $cat['@id']);
    }

    // ── Empty collection ──────────────────────────────────────────────────────

    public function testHasManyEmptyCollectionReturnsEmptyArray(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource([
            'categories' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        // Article 3 has no categories
        $response = $this->executeApiRequest('/_api/hm-articles/3');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['categories']);
    }

    // ── Collection endpoint ───────────────────────────────────────────────────

    public function testHasManyCollectionEmbedWorksForAllMembers(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource([
            'categories' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/hm-articles');
        $body     = $this->decodeResponseBody($response);

        $members = array_column($body['hydra:member'], null, 'uid');

        // Article 1 has 2 categories
        self::assertCount(2, $members[1]['categories']);
        $titles1 = array_column($members[1]['categories'], 'title');
        self::assertContains('PHP', $titles1);
        self::assertContains('TYPO3', $titles1);

        // Article 2 has 1 category
        self::assertCount(1, $members[2]['categories']);
        self::assertSame('API', $members[2]['categories'][0]['title']);

        // Article 3 has 0 categories
        self::assertSame([], $members[3]['categories']);
    }

    // ── Depth budget ──────────────────────────────────────────────────────────

    public function testHasManyDepthArrayConfig(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource([
            'categories' => ['groups' => ['list', 'show'], 'embed' => ['depth' => 1]],
        ]);

        $response = $this->executeApiRequest('/_api/hm-articles/1');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['categories']);
        // Depth 1 means full record — title should be present
        self::assertArrayHasKey('title', $body['categories'][0]);
    }
}
