<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for TCA foreign_table_field support.
 *
 * When two parent tables share a child table via foreign_field + foreign_table_field,
 * the API must return only the children that belong to the requested parent table.
 *
 * Fixture data (articles_ftf.csv + colors_ftf.csv):
 *   Article 700 → 2 children via related_items_ftf (FTF Red=720, FTF Blue=721)
 *   Color 700   → 2 children via the same foreign_field but different parent_tablename (impostors 722, 723)
 *
 * Without the fix, fetching Article 400's related_items_ftf would return all 4 records
 * because foreign_article_id=400 matches all of them.
 */
final class ForeignTableFieldEmbedTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';
    private const COLOR_TABLE   = 'tx_myext_domain_model_color';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors_ftf.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_ftf.csv');
    }

    private function registerColorResource(): void
    {
        $this->registerResource('ftf-colors', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'ftf-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show'],
            ],
            'columns' => ['name' => ['groups' => ['list', 'show']]],
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerArticleResource(array $columnOverrides = []): void
    {
        $this->registerResource('ftf-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'ftf-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => array_merge([
                'title'            => ['groups' => ['list', 'show']],
                'related_items_ftf' => ['groups' => ['list', 'show']],
            ], $columnOverrides),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Without embed: stubs ──────────────────────────────────────────────────

    public function testForeignTableFieldReturnsOnlyOwnChildrenAsStubs(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/ftf-articles/700');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['related_items_ftf']);
        // Must be exactly 2 — NOT 4 (the impostor color-parent children must be excluded)
        self::assertCount(2, $body['related_items_ftf']);
    }

    public function testForeignTableFieldStubsPointToCorrectUids(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/ftf-articles/700');
        $body     = $this->decodeResponseBody($response);

        $uids = array_map(
            static fn (string $iri) => (int)substr($iri, strrpos($iri, '/') + 1),
            $body['related_items_ftf'],
        );
        sort($uids);

        // Only 720 and 721 — not 722 or 723
        self::assertSame([720, 721], $uids);
    }

    // ── With embed=true: full records ─────────────────────────────────────────

    public function testForeignTableFieldWithEmbedReturnsOnlyOwnChildren(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_ftf' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/ftf-articles/700');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['related_items_ftf']);

        $names = array_column($body['related_items_ftf'], 'name');
        sort($names);
        self::assertSame(['FTF Blue', 'FTF Red'], $names);
    }

    public function testForeignTableFieldEmbeddedChildDoesNotContainImpostor(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_ftf' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/ftf-articles/700');
        $body     = $this->decodeResponseBody($response);

        $names = array_column($body['related_items_ftf'], 'name');
        self::assertNotContains('FTF Impostor', $names);
        self::assertNotContains('FTF Impostor2', $names);
    }

    // ── Collection endpoint: bulk preload via EmbedPreloader ─────────────────

    public function testForeignTableFieldCollectionPreloadFiltersCorrectly(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_ftf' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/ftf-articles');
        $body     = $this->decodeResponseBody($response);

        $members = array_column($body['hydra:member'], null, 'uid');

        self::assertArrayHasKey(700, $members);
        self::assertCount(2, $members[700]['related_items_ftf']);

        $names = array_column($members[700]['related_items_ftf'], 'name');
        sort($names);
        self::assertSame(['FTF Blue', 'FTF Red'], $names);
    }
}
