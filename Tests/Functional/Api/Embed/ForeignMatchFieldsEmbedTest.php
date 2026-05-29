<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for TCA foreign_match_fields support.
 *
 * When two inline columns on the same parent table point to the same child
 * table via the same foreign_field but different foreign_match_fields values,
 * each column must return only the children whose match-field value matches.
 *
 * Fixture data (articles_fmf.csv + colors_fmf.csv):
 *   Article 500 → related_items_fmf_a: FMF TypeA Red (520), FMF TypeA Blue (521)
 *   Article 500 → related_items_fmf_b: FMF TypeB Green (522)
 *
 * Without the fix, both columns return all 3 children because foreign_match_fields
 * is not applied to the WHERE clause.
 */
final class ForeignMatchFieldsEmbedTest extends ApiFunctionalTestCase
{
    private const ARTICLE_TABLE = 'tx_myext_domain_model_article';
    private const COLOR_TABLE   = 'tx_myext_domain_model_color';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors_fmf.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_fmf.csv');
    }

    private function registerColorResource(): void
    {
        $this->registerResource('fmf-colors', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'fmf-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show'],
            ],
            'columns' => ['name' => ['groups' => ['list', 'show']]],
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerArticleResource(array $columnOverrides = []): void
    {
        $this->registerResource('fmf-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'fmf-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => array_merge([
                'title'               => ['groups' => ['list', 'show']],
                'related_items_fmf_a' => ['groups' => ['list', 'show']],
                'related_items_fmf_b' => ['groups' => ['list', 'show']],
            ], $columnOverrides),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Without embed: stubs ──────────────────────────────────────────────────

    public function testMatchFieldsColumnAReturnsOnlyTypeAStubs(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/fmf-articles/500');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['related_items_fmf_a']);
    }

    public function testMatchFieldsColumnBReturnsOnlyTypeBStubs(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/fmf-articles/500');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['related_items_fmf_b']);
    }

    public function testMatchFieldsColumnAStubUids(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/fmf-articles/500');
        $body     = $this->decodeResponseBody($response);

        $uids = array_map(
            static fn (string $iri) => (int)substr($iri, strrpos($iri, '/') + 1),
            $body['related_items_fmf_a'],
        );
        sort($uids);
        self::assertSame([520, 521], $uids);
    }

    public function testMatchFieldsColumnBStubUid(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/_api/fmf-articles/500');
        $body     = $this->decodeResponseBody($response);

        $uids = array_map(
            static fn (string $iri) => (int)substr($iri, strrpos($iri, '/') + 1),
            $body['related_items_fmf_b'],
        );
        self::assertSame([522], $uids);
    }

    // ── With embed=true: full records ─────────────────────────────────────────

    public function testMatchFieldsColumnAEmbedReturnsCorrectNames(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_fmf_a' => ['groups' => ['list', 'show'], 'embed' => true],
            'related_items_fmf_b' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/fmf-articles/500');
        $body     = $this->decodeResponseBody($response);

        $names = array_column($body['related_items_fmf_a'], 'name');
        sort($names);
        self::assertSame(['FMF TypeA Blue', 'FMF TypeA Red'], $names);
    }

    public function testMatchFieldsColumnBEmbedReturnsCorrectName(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_fmf_a' => ['groups' => ['list', 'show'], 'embed' => true],
            'related_items_fmf_b' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/fmf-articles/500');
        $body     = $this->decodeResponseBody($response);

        self::assertCount(1, $body['related_items_fmf_b']);
        self::assertSame('FMF TypeB Green', $body['related_items_fmf_b'][0]['name']);
    }

    public function testMatchFieldsColumnADoesNotContainTypeBRecord(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_fmf_a' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/fmf-articles/500');
        $body     = $this->decodeResponseBody($response);

        $names = array_column($body['related_items_fmf_a'], 'name');
        self::assertNotContains('FMF TypeB Green', $names);
    }

    // ── Collection endpoint: bulk preload via EmbedPreloader ─────────────────

    public function testMatchFieldsCollectionPreloadFiltersCorrectly(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource([
            'related_items_fmf_a' => ['groups' => ['list', 'show'], 'embed' => true],
            'related_items_fmf_b' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/fmf-articles');
        $body     = $this->decodeResponseBody($response);

        $members = array_column($body['hydra:member'], null, 'uid');

        self::assertCount(2, $members[500]['related_items_fmf_a']);
        self::assertCount(1, $members[500]['related_items_fmf_b']);

        $namesA = array_column($members[500]['related_items_fmf_a'], 'name');
        sort($namesA);
        self::assertSame(['FMF TypeA Blue', 'FMF TypeA Red'], $namesA);

        self::assertSame('FMF TypeB Green', $members[500]['related_items_fmf_b'][0]['name']);
    }
}
