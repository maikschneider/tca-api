<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for language overlay on MM-embedded relations (E12 fix).
 *
 * When an article embeds categories via MM and the request is in German,
 * the embedded category titles must be overlaid with their German translations.
 *
 * Fixture data (articles_lang_embed.csv + sys_categories_lang_embed.csv + sys_category_record_mm_lang.csv):
 *   Article 800 → categories [800 (Frontend), 801 (Backend)]
 *   Article 801 → categories [802 (Fullstack)]
 *   Article 802 → categories [] (none)
 *
 *   Category 800 "Frontend" → German translation 850 "Frontend DE"
 *   Category 801 "Backend"  → German translation 851 "Backend DE"
 *   Category 802 "Fullstack" → NO German translation (fallback expected)
 */
final class LanguageEmbedOverlayTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiLanguage' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_lang_embed.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_lang_embed.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm_lang.csv');
    }

    private function registerResources(bool $embed = true): void
    {
        $this->registerResource('lang-categories', [
            'general' => [
                'table' => 'sys_category',
                'resourceName' => 'lang-categories',
                'resourceType' => 'Category',
                'operations' => ['list', 'show'],
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
            ],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        $this->registerResource('lang-embed-articles', [
            'general' => [
                'table' => 'tx_myext_domain_model_article',
                'resourceName' => 'lang-embed-articles',
                'resourceType' => 'Article',
                'operations' => ['list', 'show'],
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
                'categories' => ['groups' => ['list', 'show'], 'embed' => $embed],
            ],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── MM embed: English (default language) ─────────────────────────────────

    public function testMmEmbedInEnglishReturnsOriginalTitles(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/api/lang-embed-articles/800');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['categories']);
        self::assertCount(2, $body['categories']);

        $titles = array_column($body['categories'], 'title');
        self::assertContains('Frontend', $titles);
        self::assertContains('Backend', $titles);
    }

    // ── MM embed: German with overlay ────────────────────────────────────────

    public function testMmEmbedInGermanOverlaysCategoryTitles(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lang-embed-articles/800');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['categories']);
        self::assertCount(2, $body['categories']);

        $titles = array_column($body['categories'], 'title');
        self::assertContains('Frontend DE', $titles);
        self::assertContains('Backend DE', $titles);
    }

    public function testMmEmbedInGermanFallsBackWhenTranslationMissing(): void
    {
        $this->registerResources();

        // Article 801 → category 802 "Fullstack" has no German translation.
        // German site uses fallbackType=fallback with fallbacks='0', so it
        // should fall back to the English original.
        $response = $this->executeApiRequest('/de/api/lang-embed-articles/801');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['categories']);
        self::assertCount(1, $body['categories']);
        self::assertSame('Fullstack', $body['categories'][0]['title']);
    }

    // ── MM embed: collection endpoint ────────────────────────────────────────

    public function testMmEmbedCollectionInGermanOverlaysAllMembers(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lang-embed-articles');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());

        $members = array_column($body['hydra:member'], null, 'uid');

        // Article 800: both categories have German translations
        $titles800 = array_column($members[800]['categories'], 'title');
        self::assertContains('Frontend DE', $titles800);
        self::assertContains('Backend DE', $titles800);

        // Article 801: category 802 has no translation, fallback to English
        self::assertCount(1, $members[801]['categories']);
        self::assertSame('Fullstack', $members[801]['categories'][0]['title']);

        // Article 802: no categories
        self::assertSame([], $members[802]['categories']);
    }

    // ── MM embed: uid preserved after overlay ────────────────────────────────

    public function testMmEmbedInGermanPreservesOriginalUid(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lang-embed-articles/800');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        $uids = array_column($body['categories'], 'uid');
        sort($uids);
        // UIDs should be the default-language UIDs (800, 801), not translation UIDs (850, 851)
        self::assertSame([800, 801], $uids);
    }

    // ── MM embed: empty collection unaffected ────────────────────────────────

    public function testMmEmbedInGermanWithNoCategoriesReturnsEmptyArray(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lang-embed-articles/802');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['categories']);
    }

    // ── Without embed: IRI strings still work in translated context ──────────

    public function testMmWithoutEmbedInGermanReturnsIriStrings(): void
    {
        $this->registerResources(embed: false);

        $response = $this->executeApiRequest('/de/api/lang-embed-articles/800');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['categories']);
        self::assertCount(2, $body['categories']);
        self::assertIsString($body['categories'][0]);
        self::assertMatchesRegularExpression('#/api/[^/]+/\d+$#', $body['categories'][0]);
    }
}
