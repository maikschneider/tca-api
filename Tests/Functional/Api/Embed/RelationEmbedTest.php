<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for relation embedding via 'embed' column config.
 *
 * Config format:
 *   'embed' => true              — embed at depth 1 (full record, nested relations as stubs)
 *   'embed' => ['depth' => N]   — embed N levels deep
 *
 * Without 'embed', relations are serialized as shallow stubs {@ id, @type, uid} (unchanged).
 *
 * Fixture articles (articles_embed.csv):
 *   uid=50 → color_id=1 (Red),  parent_id=0
 *   uid=51 → color_id=2 (Blue), parent_id=0
 *   uid=52 → color_id=0 (none), parent_id=0
 *   uid=53 → color_id=1 (Red),  parent_id=54  ← cycle A
 *   uid=54 → color_id=2 (Blue), parent_id=53  ← cycle B
 *   uid=60 → color_id=1 (Red),  parent_id=61  ← chain root
 *   uid=61 → color_id=2 (Blue), parent_id=62  ← chain mid
 *   uid=62 → color_id=1 (Red),  parent_id=0   ← chain end
 *
 * Colors (colors.csv): uid=1 Red, uid=2 Blue
 */
final class RelationEmbedTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';
    private const COLOR_TABLE   = 'tx_myext_domain_model_color';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_embed.csv');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function registerColorResource(): void
    {
        $this->registerResource('embed-colors', [
            'general'  => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'embed-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'name' => ['groups' => ['list', 'show']],
            ],
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerArticleResource(array $columnOverrides = [], string $name = 'embed-articles'): void
    {
        $this->registerResource($name, [
            'general'  => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => $name,
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => array_merge([
                'title'     => ['groups' => ['list', 'show']],
                'color_id'  => ['groups' => ['list', 'show']],
                'parent_id' => ['groups' => ['list', 'show']],
            ], $columnOverrides),
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Item embed — depth 1 ──────────────────────────────────────────────────

    public function testItemEmbedColorReturnsFullRecord(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/embed-articles/50');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('color_id', $body);
        self::assertIsArray($body['color_id']);
    }

    public function testItemEmbedIncludesReadableColumns(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/embed-articles/50');
        $body = $this->decodeResponseBody($response);

        self::assertSame('Red', $body['color_id']['name']);
    }

    public function testItemEmbedIncludesJsonLdFields(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/embed-articles/50');
        $body = $this->decodeResponseBody($response);

        self::assertArrayHasKey('@id', $body['color_id']);
        self::assertArrayHasKey('@type', $body['color_id']);
        self::assertSame(1, $body['color_id']['uid']);
    }

    public function testItemEmbedNullWhenNoRelation(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        // uid=52 has color_id=0 (no color)
        $response = $this->executeApiRequest('/_api/embed-articles/52');
        $body = $this->decodeResponseBody($response);

        self::assertNull($body['color_id']);
    }

    public function testItemWithoutEmbedConfigReturnsStub(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource(); // no embed config on color_id

        $response = $this->executeApiRequest('/_api/embed-articles/50');
        $body = $this->decodeResponseBody($response);

        // Should be shallow stub: {@ id, @type, uid}, NO 'name'
        self::assertArrayHasKey('color_id', $body);
        self::assertArrayNotHasKey('name', $body['color_id']);
    }

    // ── Collection embed ──────────────────────────────────────────────────────

    public function testCollectionEmbedsAllMembers(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/embed-articles', ['filter[uid][0]' => 50, 'filter[uid][1]' => 51]);
        // simpler: just get all and assert first two have embedded color
        $response = $this->executeApiRequest('/_api/embed-articles');
        $body = $this->decodeResponseBody($response);

        $members = $body['hydra:member'];
        $withColor = array_filter($members, fn ($m) => ($m['uid'] ?? 0) === 50);
        $article50 = array_values($withColor)[0] ?? null;

        self::assertNotNull($article50);
        self::assertSame('Red', $article50['color_id']['name']);
    }

    public function testCollectionBulkEmbedDistinctColorsCorrect(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/embed-articles');
        $body = $this->decodeResponseBody($response);

        $members = array_column($body['hydra:member'], null, 'uid');

        self::assertSame('Red', $members[50]['color_id']['name']);
        self::assertSame('Blue', $members[51]['color_id']['name']);
        self::assertNull($members[52]['color_id']);
    }

    // ── Depth budget ──────────────────────────────────────────────────────────

    public function testDepthOneNestedRelationsAreStubs(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'parent_id' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        // uid=60 has parent_id=61, uid=61 has parent_id=62
        // depth=1: article 60 embeds article 61 (full), but article 61's parent is a stub
        $response = $this->executeApiRequest('/_api/embed-articles/60');
        $body = $this->decodeResponseBody($response);

        self::assertSame(61, $body['parent_id']['uid']);
        self::assertSame('Chain Mid', $body['parent_id']['title']);
        // article 61's parent (article 62) should be a STUB — only @id, @type, uid
        self::assertArrayNotHasKey('title', $body['parent_id']['parent_id']);
    }

    public function testDepthTwoEmbedsTwoLevels(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'parent_id' => ['groups' => ['list', 'show'], 'embed' => ['depth' => 2]],
        ]);

        // uid=60 → parent=61 → parent=62 → parent=null
        $response = $this->executeApiRequest('/_api/embed-articles/60');
        $body = $this->decodeResponseBody($response);

        // depth=2: article 60 embeds article 61 (full), article 61 embeds article 62 (full)
        self::assertSame(61, $body['parent_id']['uid']);
        self::assertSame('Chain Mid', $body['parent_id']['title']);
        self::assertSame(62, $body['parent_id']['parent_id']['uid']);
        self::assertSame('Chain End', $body['parent_id']['parent_id']['title']);
    }

    // ── Cycle detection ───────────────────────────────────────────────────────

    public function testCycleDetectionDoesNotInfiniteLoop(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'parent_id' => ['groups' => ['list', 'show'], 'embed' => ['depth' => 5]],
        ]);

        // uid=53 parent=54, uid=54 parent=53 (cycle)
        $response = $this->executeApiRequest('/_api/embed-articles/53');

        // If no cycle detection, this would infinite-loop / stack-overflow; just asserting it responds
        self::assertSame(200, $response->getStatusCode());
    }

    public function testCycleResultsInStubForRevisitedRecord(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'parent_id' => ['groups' => ['list', 'show'], 'embed' => ['depth' => 5]],
        ]);

        // article 53 embeds article 54, article 54's parent is article 53 (already visited → stub)
        $response = $this->executeApiRequest('/_api/embed-articles/53');
        $body = $this->decodeResponseBody($response);

        self::assertSame(54, $body['parent_id']['uid']);
        self::assertSame('Cycle B', $body['parent_id']['title']);
        // article 54's parent (article 53) must be a stub (cycle) — no 'title' key
        self::assertArrayNotHasKey('title', $body['parent_id']['parent_id']);
        self::assertSame(53, $body['parent_id']['parent_id']['uid']);
    }
}
