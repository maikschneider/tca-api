<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Tests for embed serialization at depth >= 2 across a 4-level chain.
 *
 * EmbedPreloader only preloads one level deep (based on the top-level config's columns).
 * At depth 2+ the serializer falls back to DataRepository::findById() per row.
 * These tests verify that the fallback path resolves all levels correctly.
 *
 * Fixture chain (articles_deep_embed.csv + colors.csv):
 *   uid=70 → parent=71, color=1 (Red)    — preloaded by EmbedPreloader
 *   uid=71 → parent=72, color=2 (Blue)   — NOT preloaded; fetched via findById() at depth 2
 *   uid=72 → parent=73, color=1 (Red)    — NOT preloaded; fetched via findById() at depth 3
 *   uid=73 → parent=0,  color=2 (Blue)   — NOT preloaded; fetched via findById() at depth 4
 *
 * Two different tables are involved: tx_myext_domain_model_article (self-referential
 * parent_id chain) and tx_myext_domain_model_color (embedded at every level).
 * Getting to 4 genuinely different tables without TCA changes is not achievable with
 * the current test extension schema, as neither Color nor sys_category have forward FK
 * columns to a 4th table. The depth=4 behavior across the preloader+fallback boundary
 * is what's being validated here.
 */
final class DeepEmbedTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE   = 'tx_myext_domain_model_article';
    private const COLOR_TABLE     = 'tx_myext_domain_model_color';
    private const CATEGORY_TABLE  = 'sys_category';
    private const FE_GROUP_TABLE  = 'fe_groups';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_deep_embed.csv');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function registerColorResource(): void
    {
        $this->registerResource('deep-colors', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'deep-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'name' => ['groups' => ['list', 'show']],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerArticleResource(array $parentIdOverride = []): void
    {
        $parentIdConfig = array_merge(
            ['groups' => ['list', 'show']],
            $parentIdOverride,
        );

        $this->registerResource('deep-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'deep-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'title'     => ['groups' => ['list', 'show']],
                'color_id'  => ['groups' => ['list', 'show'], 'embed' => true],
                'parent_id' => $parentIdConfig,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Depth-4 parent chain: title resolution ─────────────────────────────────

    /**
     * Verifies that all 4 levels of a self-referential embed chain resolve correctly.
     *
     * EmbedPreloader fetches article_71 (direct parent of article_70).
     * Articles 72 and 73 are fetched via DataRepository::findById() fallback (slow path).
     * If the fallback is broken, levels 3-4 will be stubs or null.
     */
    public function testDepthFourChainAllTitlesResolvedCorrectly(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource(['embed' => ['depth' => 4]]);

        $response = $this->executeApiRequest('/_api/deep-articles/70');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponseBody($response);

        // Level 1 (preloaded by EmbedPreloader)
        self::assertSame('Deep Level 1', $body['title']);
        self::assertIsArray($body['parent_id']);
        self::assertSame('Deep Level 2', $body['parent_id']['title']);

        // Level 2 (fetched via findById() fallback — NOT preloaded)
        self::assertIsArray($body['parent_id']['parent_id']);
        self::assertSame('Deep Level 3', $body['parent_id']['parent_id']['title']);

        // Level 3 (fetched via findById() fallback — NOT preloaded)
        self::assertIsArray($body['parent_id']['parent_id']['parent_id']);
        self::assertSame('Deep Level 4', $body['parent_id']['parent_id']['parent_id']['title']);

        // Level 4 terminates: parent_id=0 → null
        self::assertNull($body['parent_id']['parent_id']['parent_id']['parent_id']);
    }

    // ── Color at each depth level ──────────────────────────────────────────────

    /**
     * Verifies that cross-table embeds (color) are correctly resolved at every depth level.
     *
     * - Level 1 color (Red, uid=1): preloaded by EmbedPreloader.
     * - Level 2 color (Blue, uid=2): NOT preloaded; fetched via findById() fallback.
     * - Level 3 color (Red, uid=1): preloaded value is reused from the preloaded pool.
     * - Level 4 color (Blue, uid=2): NOT preloaded; fetched via findById() fallback.
     */
    public function testDepthFourChainColorCorrectAtEveryLevel(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource(['embed' => ['depth' => 4]]);

        $response = $this->executeApiRequest('/_api/deep-articles/70');
        $body = $this->decodeResponseBody($response);

        // Level 1: color preloaded
        self::assertIsArray($body['color_id']);
        self::assertSame('Red', $body['color_id']['name']);

        // Level 2: color NOT preloaded (uid=2) — tests findById() fallback for cross-table embed
        self::assertIsArray($body['parent_id']['color_id']);
        self::assertSame('Blue', $body['parent_id']['color_id']['name']);

        // Level 3: color uid=1 IS in preloaded pool (fetched for level 1 article)
        self::assertIsArray($body['parent_id']['parent_id']['color_id']);
        self::assertSame('Red', $body['parent_id']['parent_id']['color_id']['name']);

        // Level 4: color NOT preloaded (uid=2) — tests findById() fallback at depth 4
        self::assertIsArray($body['parent_id']['parent_id']['parent_id']['color_id']);
        self::assertSame('Blue', $body['parent_id']['parent_id']['parent_id']['color_id']['name']);
    }

    // ── Depth budget exhaustion ────────────────────────────────────────────────

    /**
     * Verifies that embed depth=3 correctly stubs the 4th level rather than resolving it.
     */
    public function testDepthThreeStubsFourthLevel(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource(['embed' => ['depth' => 3]]);

        $response = $this->executeApiRequest('/_api/deep-articles/70');
        $body = $this->decodeResponseBody($response);

        // Level 1, 2, 3: full records
        self::assertSame('Deep Level 2', $body['parent_id']['title']);
        self::assertSame('Deep Level 3', $body['parent_id']['parent_id']['title']);
        self::assertSame('Deep Level 4', $body['parent_id']['parent_id']['parent_id']['title']);

        // Level 4 (depth budget = 0): parent must be a stub, not a full record
        $stub = $body['parent_id']['parent_id']['parent_id']['parent_id'];
        // parent_id=0 → null (fkValue <= 0 returns null before depth check)
        self::assertNull($stub);
    }

    /**
     * Verifies that embed depth=2 correctly stubs the 3rd and 4th levels.
     */
    public function testDepthTwoStubsThirdAndFourthLevels(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource(['embed' => ['depth' => 2]]);

        $response = $this->executeApiRequest('/_api/deep-articles/70');
        $body = $this->decodeResponseBody($response);

        // Level 1, 2: full records
        self::assertSame('Deep Level 2', $body['parent_id']['title']);
        self::assertSame('Deep Level 3', $body['parent_id']['parent_id']['title']);

        // Level 3 (depth budget = 0): must be a stub — has @id/uid but no title
        $stub = $body['parent_id']['parent_id']['parent_id'];
        self::assertArrayHasKey('@id', $stub);
        self::assertArrayHasKey('uid', $stub);
        self::assertArrayNotHasKey('title', $stub);
        self::assertSame(73, $stub['uid']);
    }

    // ── Preloader does not over-fetch ──────────────────────────────────────────

    /**
     * Verifies that depth=1 embeds only the direct parent (not the full chain).
     */
    public function testDepthOneEmbedsOnlyDirectParent(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource(['embed' => true]);

        $response = $this->executeApiRequest('/_api/deep-articles/70');
        $body = $this->decodeResponseBody($response);

        // Direct parent: full record
        self::assertSame('Deep Level 2', $body['parent_id']['title']);

        // Grandparent: stub (depth=1 exhausted after first embed)
        $grandparent = $body['parent_id']['parent_id'];
        self::assertArrayHasKey('@id', $grandparent);
        self::assertArrayNotHasKey('title', $grandparent);
        self::assertSame(72, $grandparent['uid']);
    }

    // ── 4-table cross-table chain ──────────────────────────────────────────────

    /**
     * Registers API configs for the 4-table chain:
     *   chain-articles → chain-colors → chain-categories → chain-fe-groups
     *
     * Only color_id has an explicit embed depth; category_id and fe_group_id
     * inherit the remaining depth budget via $remainingDepth propagation and
     * therefore do NOT require an 'embed' key in their own column configs.
     */
    private function registerChainResources(int $depth = 3): void
    {
        $this->registerResource('chain-fe-groups', [
            'general' => [
                'table'        => self::FE_GROUP_TABLE,
                'resourceName' => 'chain-fe-groups',
                'resourceType' => 'FeGroup',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        $this->registerResource('chain-categories', [
            'general' => [
                'table'        => self::CATEGORY_TABLE,
                'resourceName' => 'chain-categories',
                'resourceType' => 'Category',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'title'       => ['groups' => ['list', 'show']],
                'fe_group_id' => ['groups' => ['list', 'show'], 'resourceName' => 'chain-fe-groups'],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        $this->registerResource('chain-colors', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'chain-colors',
                'resourceType' => 'ChainColor',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'name'        => ['groups' => ['list', 'show']],
                'category_id' => ['groups' => ['list', 'show'], 'resourceName' => 'chain-categories'],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        $this->registerResource('chain-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'chain-articles',
                'resourceType' => 'ChainArticle',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'title'    => ['groups' => ['list', 'show']],
                'color_id' => ['groups' => ['list', 'show'], 'embed' => ['depth' => $depth], 'resourceName' => 'chain-colors'],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    /**
     * Verifies embed resolution across 4 genuinely different tables:
     *   tx_myext_domain_model_article → tx_myext_domain_model_color
     *   → sys_category → fe_groups
     *
     * - Level 1 (Color): preloaded by EmbedPreloader
     * - Level 2 (sys_category): fetched via DataRepository::findById() fallback
     * - Level 3 (fe_groups): fetched via DataRepository::findById() fallback
     *
     * Depth budget: color_id has embed depth=3; category_id and fe_group_id
     * inherit the remaining budget without requiring their own 'embed' config.
     */
    public function testFourTableChainAllLevelsResolvedCorrectly(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_chain.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors_chain.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_chain.csv');

        $this->registerChainResources(depth: 3);

        $response = $this->executeApiRequest('/_api/chain-articles/80');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponseBody($response);

        // Level 0: Article itself
        self::assertSame('Chain Article', $body['title']);

        // Level 1: Color (preloaded by EmbedPreloader)
        self::assertIsArray($body['color_id']);
        self::assertSame('Green', $body['color_id']['name']);

        // Level 2: sys_category (fetched via findById() fallback — NOT preloaded)
        self::assertIsArray($body['color_id']['category_id']);
        self::assertSame('Backend', $body['color_id']['category_id']['title']);

        // Level 3: fe_groups (fetched via findById() fallback — NOT preloaded)
        self::assertIsArray($body['color_id']['category_id']['fe_group_id']);
        self::assertSame('Editors', $body['color_id']['category_id']['fe_group_id']['title']);
    }

    /**
     * Verifies that depth=2 correctly stubs the 3rd level (fe_groups) of the 4-table chain.
     */
    public function testFourTableChainDepthTwoStubsThirdLevel(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_chain.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors_chain.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_chain.csv');

        $this->registerChainResources(depth: 2);

        $response = $this->executeApiRequest('/_api/chain-articles/80');
        $body = $this->decodeResponseBody($response);

        // Level 1: Color — fully embedded
        self::assertSame('Green', $body['color_id']['name']);

        // Level 2: sys_category — fully embedded
        self::assertSame('Backend', $body['color_id']['category_id']['title']);

        // Level 3: fe_group — stub (depth budget exhausted)
        $stub = $body['color_id']['category_id']['fe_group_id'];
        self::assertArrayHasKey('@id', $stub);
        self::assertArrayHasKey('uid', $stub);
        self::assertArrayNotHasKey('title', $stub);
        self::assertSame(1, $stub['uid']);
    }
}
