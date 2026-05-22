<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for TCA type=inline column serialization.
 *
 * type=inline with foreign_field='foreign_article_id' stores the relationship
 * on the child side: colors carry a back-pointer to their parent article.
 * EmbedPreloader resolves this via findHasManyByForeignField() (line 124).
 *
 * Fixtures (articles_inline.csv + colors_inline.csv):
 *   Article 300 → 2 inline colors: InlineRed (10), InlineBlue (11)
 *   Article 301 → 1 inline color:  InlineGreen (12)
 *   Article 302 → 0 inline colors
 *   Article 303 → 1 inline color:  InlineCyan (13)
 *
 * Colors carry foreign_article_id pointing back to the parent article.
 */
final class InlineEmbedTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';
    private const COLOR_TABLE   = 'tx_myext_domain_model_color';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors_inline.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_inline.csv');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function registerColorResource(): void
    {
        $this->registerResource('inline-colors', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'inline-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show'],
            ],
            'columns' => ['name' => ['groups' => ['list', 'show']]],
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerArticleResource(array $columnOverrides = []): void
    {
        $this->registerResource('inline-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'inline-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => array_merge([
                'title'                => ['groups' => ['list', 'show']],
                'related_items_inline' => ['groups' => ['list', 'show']],
            ], $columnOverrides),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Without embed: stubs ──────────────────────────────────────────────────

    public function testInlineWithoutEmbedReturnsTwoStubs(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/inline-articles/300');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['related_items_inline']);
        self::assertCount(2, $body['related_items_inline']);
    }

    public function testInlineWithoutEmbedReturnsIriStrings(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/inline-articles/300');
        $body     = $this->decodeResponseBody($response);

        self::assertIsString($body['related_items_inline'][0]);
        self::assertMatchesRegularExpression('#/_api/[^/]+/\d+$#', $body['related_items_inline'][0]);
    }

    public function testInlineSingleRelationWithoutEmbedReturnsIri(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/inline-articles/301');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['related_items_inline']);
        self::assertIsString($body['related_items_inline'][0]);
        self::assertStringEndsWith('/12', $body['related_items_inline'][0]);
    }

    public function testInlineEmptyWithoutEmbedReturnsEmptyArray(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/inline-articles/302');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['related_items_inline']);
    }

    // ── With embed=true: full records ─────────────────────────────────────────

    public function testInlineWithEmbedReturnsTwoFullColorRecords(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_inline' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/inline-articles/300');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['related_items_inline']);

        $names = array_column($body['related_items_inline'], 'name');
        self::assertContains('InlineRed', $names);
        self::assertContains('InlineBlue', $names);
    }

    public function testInlineWithEmbedReturnsOneColorRecord(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_inline' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/inline-articles/301');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['related_items_inline']);
        self::assertSame('InlineGreen', $body['related_items_inline'][0]['name']);
    }

    public function testInlineEmptyWithEmbedReturnsEmptyArray(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_inline' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/inline-articles/302');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['related_items_inline']);
    }

    public function testInlineEmbeddedItemHasJsonLdFields(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_inline' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/inline-articles/301');
        $body     = $this->decodeResponseBody($response);

        $color = $body['related_items_inline'][0];
        self::assertArrayHasKey('@id', $color);
        self::assertArrayHasKey('@type', $color);
        self::assertArrayHasKey('uid', $color);
        self::assertSame('Color', $color['@type']);
        self::assertStringStartsWith('/_api/', $color['@id']);
    }

    // ── embed=false: same as no embed ─────────────────────────────────────────

    public function testInlineEmbedFalseReturnsStubs(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_inline' => ['groups' => ['list', 'show'], 'embed' => false],
        ]);

        $response = $this->executeApiRequest('/_api/inline-articles/300');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['related_items_inline']);
        self::assertIsString($body['related_items_inline'][0]);
    }

    // ── embed=['depth'=>1]: same as embed=true ────────────────────────────────

    public function testInlineDepthArrayConfigReturnsFullRecords(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_inline' => ['groups' => ['list', 'show'], 'embed' => ['depth' => 1]],
        ]);

        $response = $this->executeApiRequest('/_api/inline-articles/300');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['related_items_inline']);
        self::assertArrayHasKey('name', $body['related_items_inline'][0]);
    }

    // ── Collection endpoint: bulk preload via foreign_field ───────────────────

    public function testInlineCollectionPreloadWorks(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_inline' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/inline-articles');
        $body     = $this->decodeResponseBody($response);

        $members = array_column($body['hydra:member'], null, 'uid');

        self::assertCount(2, $members[300]['related_items_inline']);
        self::assertCount(1, $members[301]['related_items_inline']);
        self::assertSame([], $members[302]['related_items_inline']);
        self::assertCount(1, $members[303]['related_items_inline']);
        self::assertSame('InlineCyan', $members[303]['related_items_inline'][0]['name']);
    }
}
