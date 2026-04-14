<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Registry\ApiRegistry;
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
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function registerColorResource(): void
    {
        ApiRegistry::register('grp-colors', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'grp-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show'],
                'itemsPerPage' => 20,
            ],
            'columns' => ['name' => ['groups' => ['list', 'show']]],
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerArticleResource(array $columnOverrides = []): void
    {
        ApiRegistry::register('grp-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'grp-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
                'itemsPerPage' => 20,
            ],
            'columns' => array_merge([
                'title'          => ['groups' => ['list', 'show']],
                'related_colors' => ['groups' => ['list', 'show']],
                'related_items'  => ['groups' => ['list', 'show']],
            ], $columnOverrides),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Single-table group: stubs ─────────────────────────────────────────────

    public function testGroupSingleTableWithoutEmbedReturnsStubs(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/grp-articles/200');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['related_colors']);
        self::assertCount(2, $body['related_colors']);
        self::assertArrayHasKey('@id', $body['related_colors'][0]);
        self::assertArrayHasKey('@type', $body['related_colors'][0]);
        self::assertArrayHasKey('uid', $body['related_colors'][0]);
        self::assertArrayNotHasKey('name', $body['related_colors'][0]);
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

    public function testGroupMultiTableReturnsStubsFromDifferentTables(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        // Article 200: related_items="tx_myext_domain_model_article_201,tx_myext_domain_model_color_1"
        $response = $this->executeApiRequest('/_api/grp-articles/200');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['related_items']);
        self::assertCount(2, $body['related_items']);

        // Each item is a stub with @id, @type, uid
        foreach ($body['related_items'] as $stub) {
            self::assertArrayHasKey('@id', $stub);
            self::assertArrayHasKey('@type', $stub);
            self::assertArrayHasKey('uid', $stub);
        }

        $uids  = array_column($body['related_items'], 'uid');
        $types = array_column($body['related_items'], '@type');
        self::assertContains(201, $uids);
        self::assertContains(1, $uids);
        self::assertContains('Article', $types);
        self::assertContains('Color', $types);
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

    public function testGroupMultiTableSingleItemStub(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        // Article 203: related_items="tx_myext_domain_model_color_2"
        $response = $this->executeApiRequest('/_api/grp-articles/203');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['related_items']);
        self::assertSame(2, $body['related_items'][0]['uid']);
        self::assertSame('Color', $body['related_items'][0]['@type']);
    }

    // ── Group with MM table ───────────────────────────────────────────────────

    private function registerMmArticleResource(array $columnOverrides = []): void
    {
        ApiRegistry::register('grp-mm-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'grp-mm-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
                'itemsPerPage' => 20,
            ],
            'columns' => array_merge([
                'title'                 => ['groups' => ['list', 'show']],
                'related_colors_mm_grp' => ['groups' => ['list', 'show']],
            ], $columnOverrides),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    public function testGroupMmFieldWithoutEmbedReturnsStubs(): void
    {
        $this->registerColorResource();
        $this->registerMmArticleResource();

        $response = $this->executeApiRequest('/_api/grp-mm-articles/204');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['related_colors_mm_grp']);
        self::assertCount(2, $body['related_colors_mm_grp']);
        self::assertArrayHasKey('@id', $body['related_colors_mm_grp'][0]);
        self::assertArrayHasKey('@type', $body['related_colors_mm_grp'][0]);
        self::assertArrayHasKey('uid', $body['related_colors_mm_grp'][0]);
        self::assertArrayNotHasKey('name', $body['related_colors_mm_grp'][0]);
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
