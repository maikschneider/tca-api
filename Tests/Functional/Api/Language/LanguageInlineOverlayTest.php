<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for language overlay on foreignField (inline) relations (E12 fix).
 *
 * The tx_myext_domain_model_color table does NOT have language fields,
 * so the overlay logic must gracefully skip it. This test ensures that
 * inline-embedded children are still returned correctly even when the
 * request is in a non-default language.
 *
 * Fixture data (articles_inline.csv + colors_inline.csv):
 *   Article 300 → 2 inline colors: InlineRed (10), InlineBlue (11)
 *   Article 301 → 1 inline color:  InlineGreen (12)
 *   Article 302 → 0 inline colors
 */
final class LanguageInlineOverlayTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiLanguage' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors_inline.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_inline.csv');
    }

    private function registerResources(): void
    {
        $this->registerResource('lang-inline-colors', [
            'general' => [
                'table' => 'tx_myext_domain_model_color',
                'resourceName' => 'lang-inline-colors',
                'resourceType' => 'Color',
                'operations' => ['list', 'show'],
            ],
            'columns' => ['name' => ['groups' => ['list', 'show']]],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        $this->registerResource('lang-inline-articles', [
            'general' => [
                'table' => 'tx_myext_domain_model_article',
                'resourceName' => 'lang-inline-articles',
                'resourceType' => 'Article',
                'operations' => ['list', 'show'],
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
                'related_items_inline' => ['groups' => ['list', 'show'], 'embed' => true],
            ],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Inline embed in German: no crash, returns children as-is ─────────────

    public function testInlineEmbedInGermanReturnsChildrenWithoutOverlay(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lang-inline-articles/300');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['related_items_inline']);
        self::assertCount(2, $body['related_items_inline']);

        $names = array_column($body['related_items_inline'], 'name');
        self::assertContains('InlineRed', $names);
        self::assertContains('InlineBlue', $names);
    }

    public function testInlineEmbedSingleChildInGermanReturnsCorrectly(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lang-inline-articles/301');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['related_items_inline']);
        self::assertSame('InlineGreen', $body['related_items_inline'][0]['name']);
    }

    public function testInlineEmbedEmptyInGermanReturnsEmptyArray(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lang-inline-articles/302');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['related_items_inline']);
    }

    // ── Collection endpoint in German ────────────────────────────────────────

    public function testInlineEmbedCollectionInGermanPreloadsCorrectly(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lang-inline-articles');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());

        $members = array_column($body['hydra:member'], null, 'uid');

        self::assertCount(2, $members[300]['related_items_inline']);
        self::assertCount(1, $members[301]['related_items_inline']);
        self::assertSame([], $members[302]['related_items_inline']);
    }
}
