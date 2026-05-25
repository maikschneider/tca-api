<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for TCA type=group column serialization.
 *
 * type=group stores related UIDs differently depending on the number of allowed tables:
 *   - Single allowed table: plain UID list "1,2" (same format as UID-list hasMany)
 *   - Multiple allowed tables: prefixed list "tablename_uid", e.g. "pages_1,sys_file_3"
 *
 * Fixtures (articles_group.csv + colors.csv):
 *   Article 200 → related_colors="1,2",  related_items="tx_myext_domain_model_article_201,tx_myext_domain_model_color_1"
 *   Article 201 → related_colors="1",    related_items=""
 *   Article 202 → related_colors="",     related_items=""
 *   Article 203 → related_colors="",     related_items="tx_myext_domain_model_color_2"
 *
 *   Color uid=1 → Red
 *   Color uid=2 → Blue
 *
 * Fixtures (articles_group_mm.csv + tx_myext_article_colors_mm.csv + colors.csv):
 *   Article 204 → related_colors_mm_grp=2 (count), MM: colors 1,2
 *   Article 205 → related_colors_mm_grp=1 (count), MM: color 1
 *   Article 206 → related_colors_mm_grp=0 (count), MM: —
 */
final class GroupFieldEmbedTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';
    private const COLOR_TABLE   = 'tx_myext_domain_model_color';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_group.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_group_mm.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tx_myext_article_colors_mm.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_group_missing.csv');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function registerColorResource(): void
    {
        $this->registerResource('grp-colors', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'grp-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show'],
            ],
            'columns' => ['name' => ['groups' => ['list', 'show']]],
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerArticleResource(array $columnOverrides = []): void
    {
        $this->registerResource('grp-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'grp-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => array_merge([
                'title'          => ['groups' => ['list', 'show']],
                'related_colors' => ['groups' => ['list', 'show']],
                'related_items'  => ['groups' => ['list', 'show']],
            ], $columnOverrides),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Single-table group: IRI strings ──────────────────────────────────────

    public function testGroupSingleTableWithoutEmbedReturnsIriStrings(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/grp-articles/200');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['related_colors']);
        self::assertCount(2, $body['related_colors']);
        self::assertIsString($body['related_colors'][0]);
        self::assertMatchesRegularExpression('#/_api/[^/]+/\d+$#', $body['related_colors'][0]);
    }

    // ── Single-table group: full embed ────────────────────────────────────────

    public function testGroupSingleTableWithEmbedReturnsTwoColors(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_colors' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/grp-articles/200');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['related_colors']);
        self::assertCount(2, $body['related_colors']);

        $names = array_column($body['related_colors'], 'name');
        self::assertContains('Red', $names);
        self::assertContains('Blue', $names);
    }

    public function testGroupSingleTableWithEmbedReturnsOneColor(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_colors' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/grp-articles/201');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['related_colors']);
        self::assertSame('Red', $body['related_colors'][0]['name']);
    }

    public function testGroupSingleTableEmptyValueReturnsEmptyArray(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_colors' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        // Article 202 has related_colors=""
        $response = $this->executeApiRequest('/_api/grp-articles/202');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['related_colors']);
    }

    public function testGroupSingleTableEmbeddedItemHasJsonLdFields(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_colors' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/grp-articles/201');
        $body     = $this->decodeResponseBody($response);

        $color = $body['related_colors'][0];
        self::assertArrayHasKey('@id', $color);
        self::assertArrayHasKey('@type', $color);
        self::assertSame('Color', $color['@type']);
        self::assertStringStartsWith('/_api/', $color['@id']);
    }

    // ── Single-table group: collection bulk preload ───────────────────────────

    public function testGroupSingleTableCollectionPreloadWorks(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_colors' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/grp-articles');
        $body     = $this->decodeResponseBody($response);

        $members = array_column($body['hydra:member'], null, 'uid');

        self::assertCount(2, $members[200]['related_colors']);
        self::assertCount(1, $members[201]['related_colors']);
        self::assertSame([], $members[202]['related_colors']);
    }

    // ── Multi-table group ─────────────────────────────────────────────────────

    public function testGroupMultiTableReturnsIriStringsFromDifferentTables(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        // Article 200: related_items="tx_myext_domain_model_article_201,tx_myext_domain_model_color_1"
        $response = $this->executeApiRequest('/_api/grp-articles/200');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['related_items']);
        self::assertCount(2, $body['related_items']);

        // Each item is a plain IRI string
        foreach ($body['related_items'] as $iri) {
            self::assertIsString($iri);
            self::assertMatchesRegularExpression('#/_api/[^/]+/\d+$#', $iri);
        }

        self::assertContains('/_api/grp-articles/201', $body['related_items']);
        self::assertContains('/_api/colors/1', $body['related_items']);
    }

    public function testGroupMultiTableEmptyValueReturnsEmptyArray(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        // Article 201 has related_items=""
        $response = $this->executeApiRequest('/_api/grp-articles/201');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['related_items']);
    }

    public function testGroupMultiTableSingleItemIriString(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        // Article 203: related_items="tx_myext_domain_model_color_2"
        $response = $this->executeApiRequest('/_api/grp-articles/203');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['related_items']);
        self::assertSame('/_api/colors/2', $body['related_items'][0]);
    }

    // ── Multi-table group: full embed ────────────────────────────────────────

    public function testGroupMultiTableWithEmbedReturnsFullObjects(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        // Article 200: related_items="tx_myext_domain_model_article_201,tx_myext_domain_model_color_1"
        $response = $this->executeApiRequest('/_api/grp-articles/200');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['related_items']);
        self::assertCount(2, $body['related_items']);

        // First item is an article
        $article = $body['related_items'][0];
        self::assertIsArray($article);
        self::assertArrayHasKey('@id', $article);
        self::assertArrayHasKey('@type', $article);
        self::assertArrayHasKey('uid', $article);
        self::assertSame(201, $article['uid']);
        self::assertSame('Article', $article['@type']);
        self::assertStringContainsString('/grp-articles/201', $article['@id']);

        // Second item is a color
        $color = $body['related_items'][1];
        self::assertIsArray($color);
        self::assertArrayHasKey('@id', $color);
        self::assertArrayHasKey('@type', $color);
        self::assertArrayHasKey('uid', $color);
        self::assertSame(1, $color['uid']);
        self::assertSame('Color', $color['@type']);
        self::assertStringContainsString('/colors/1', $color['@id']);
        self::assertSame('Red', $color['name']);
    }

    public function testGroupMultiTableWithEmbedEmptyReturnsEmptyArray(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        // Article 201 has related_items=""
        $response = $this->executeApiRequest('/_api/grp-articles/201');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['related_items']);
    }

    public function testGroupMultiTableWithEmbedSingleItem(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        // Article 203: related_items="tx_myext_domain_model_color_2"
        $response = $this->executeApiRequest('/_api/grp-articles/203');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['related_items']);

        $color = $body['related_items'][0];
        self::assertIsArray($color);
        self::assertSame('Color', $color['@type']);
        self::assertSame(2, $color['uid']);
        self::assertSame('Blue', $color['name']);
    }

    public function testGroupMultiTableWithEmbedCollectionPreload(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/grp-articles');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        $members = array_column($body['hydra:member'], null, 'uid');

        // Article 200 has 2 embedded items
        self::assertCount(2, $members[200]['related_items']);
        self::assertIsArray($members[200]['related_items'][0]);
        self::assertIsArray($members[200]['related_items'][1]);

        // Article 201 has no items
        self::assertSame([], $members[201]['related_items']);

        // Article 202 has no items
        self::assertSame([], $members[202]['related_items']);

        // Article 203 has 1 embedded item
        self::assertCount(1, $members[203]['related_items']);
        self::assertIsArray($members[203]['related_items'][0]);
        self::assertSame('Blue', $members[203]['related_items'][0]['name']);
    }

    public function testGroupMultiTableEmbedPreservesOrder(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        // Article 200: related_items="tx_myext_domain_model_article_201,tx_myext_domain_model_color_1"
        $response = $this->executeApiRequest('/_api/grp-articles/200');
        $body     = $this->decodeResponseBody($response);

        // Order must match DB field value
        self::assertSame(201, $body['related_items'][0]['uid']);
        self::assertSame('Article', $body['related_items'][0]['@type']);
        self::assertSame(1, $body['related_items'][1]['uid']);
        self::assertSame('Color', $body['related_items'][1]['@type']);
    }

    // ── Multi-table group: missingByTable fallback ───────────────────────────

    public function testGroupMultiTableEmbedMissingRowIsSkipped(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        // Article 210: related_items="tx_myext_domain_model_color_99,tx_myext_domain_model_color_1"
        // uid=99 does not exist → missingByTable fetch returns nothing → item skipped
        $response = $this->executeApiRequest('/_api/grp-articles/210');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['related_items']);
        self::assertSame(1, $body['related_items'][0]['uid']);
        self::assertSame('Red', $body['related_items'][0]['name']);
    }

    public function testGroupMultiTableEmbedInvalidTableIsSkipped(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        // Article 211: related_items="stale_table_99,tx_myext_domain_model_color_1"
        // stale_table is not in the field's allowed list → filtered by EmbedPreloader → no 500
        $response = $this->executeApiRequest('/_api/grp-articles/211');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['related_items']);
        self::assertSame(1, $body['related_items'][0]['uid']);
        self::assertSame('Red', $body['related_items'][0]['name']);
    }

    // ── Group with MM table ───────────────────────────────────────────────────

    private function registerMmArticleResource(array $columnOverrides = []): void
    {
        $this->registerResource('grp-mm-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'grp-mm-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => array_merge([
                'title'                 => ['groups' => ['list', 'show']],
                'related_colors_mm_grp' => ['groups' => ['list', 'show']],
            ], $columnOverrides),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    public function testGroupMmFieldWithoutEmbedReturnsIriStrings(): void
    {
        $this->registerColorResource();
        $this->registerMmArticleResource();

        $response = $this->executeApiRequest('/_api/grp-mm-articles/204');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['related_colors_mm_grp']);
        self::assertCount(2, $body['related_colors_mm_grp']);
        self::assertIsString($body['related_colors_mm_grp'][0]);
        self::assertMatchesRegularExpression('#/_api/[^/]+/\d+$#', $body['related_colors_mm_grp'][0]);
    }

    public function testGroupMmFieldWithEmbedReturnsTwoColors(): void
    {
        $this->registerColorResource();
        $this->registerMmArticleResource([
            'related_colors_mm_grp' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/grp-mm-articles/204');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['related_colors_mm_grp']);

        $names = array_column($body['related_colors_mm_grp'], 'name');
        self::assertContains('Red', $names);
        self::assertContains('Blue', $names);
    }

    public function testGroupMmFieldWithEmbedReturnsOneColor(): void
    {
        $this->registerColorResource();
        $this->registerMmArticleResource([
            'related_colors_mm_grp' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/grp-mm-articles/205');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['related_colors_mm_grp']);
        self::assertSame('Red', $body['related_colors_mm_grp'][0]['name']);
    }

    public function testGroupMmFieldEmptyReturnsEmptyArray(): void
    {
        $this->registerColorResource();
        $this->registerMmArticleResource([
            'related_colors_mm_grp' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/grp-mm-articles/206');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['related_colors_mm_grp']);
    }

    public function testGroupMmFieldCollectionPreloadWorks(): void
    {
        $this->registerColorResource();
        $this->registerMmArticleResource([
            'related_colors_mm_grp' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/grp-mm-articles');
        $body     = $this->decodeResponseBody($response);

        $members = array_column($body['hydra:member'], null, 'uid');

        self::assertCount(2, $members[204]['related_colors_mm_grp']);
        self::assertCount(1, $members[205]['related_colors_mm_grp']);
        self::assertSame([], $members[206]['related_colors_mm_grp']);
    }
}
