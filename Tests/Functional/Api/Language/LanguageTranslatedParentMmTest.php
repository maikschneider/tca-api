<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for MM-embedded relations when the parent record itself is translated.
 *
 * tx_myext_domain_model_article now has languageField + transOrigPointerField. When an
 * article is requested in German, applyLanguageOverlay merges the translation row but
 * preserves the default-language uid. The subsequent MM lookup (uid_foreign IN (defaultUid))
 * must find the categories stored under the default-language uid — and those categories
 * must then be overlaid to German as well.
 *
 * Fixture data:
 *   Article 900 (EN) / 950 (DE, l18n_parent=900) → categories [910 Alpha, 911 Beta]
 *   Article 901 (EN) / 951 (DE, l18n_parent=901) → category  [912 Gamma] (no DE translation)
 *   Article 902 (EN, no DE translation)           → no categories
 *
 *   Category 910 "Alpha"  → DE translation 960 "Alpha DE"
 *   Category 911 "Beta"   → DE translation 961 "Beta DE"
 *   Category 912 "Gamma"  → no DE translation (fallback expected)
 */
final class LanguageTranslatedParentMmTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiLanguage' => 'typo3conf/sites',
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/fileadmin/user_upload' => 'fileadmin/user_upload',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_lang_parent.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_lang_parent.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm_lang_parent.csv');
    }

    private function registerResources(bool $embed = true): void
    {
        $this->registerResource('lp-categories', [
            'general' => [
                'table'        => 'sys_category',
                'resourceName' => 'lp-categories',
                'resourceType' => 'Category',
                'operations'   => ['list', 'show'],
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

        $this->registerResource('lp-articles', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'lp-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'title'      => ['groups' => ['list', 'show']],
                'categories' => ['groups' => ['list', 'show'], 'embed' => $embed],
            ],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── English (default language) — baseline ────────────────────────────────

    public function testEnglishParentWithMmCategoriesReturnsEnglishTitles(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/api/lp-articles/900');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('LangParent Article EN', $body['title']);
        self::assertIsArray($body['categories']);
        self::assertCount(2, $body['categories']);

        $titles = array_column($body['categories'], 'title');
        self::assertContains('Alpha', $titles);
        self::assertContains('Beta', $titles);
    }

    // ── German: translated parent → mm children also overlaid ───────────────

    public function testTranslatedParentHasMmCategoriesOverlaidToGerman(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lp-articles/900');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('LangParent Artikel DE', $body['title']);
        self::assertIsArray($body['categories']);
        self::assertCount(2, $body['categories']);

        $titles = array_column($body['categories'], 'title');
        self::assertContains('Alpha DE', $titles);
        self::assertContains('Beta DE', $titles);
    }

    public function testTranslatedParentMmCategoryFallsBackWhenTranslationMissing(): void
    {
        $this->registerResources();

        // Article 901 (DE: 951) → category 912 "Gamma" has no German translation.
        $response = $this->executeApiRequest('/de/api/lp-articles/901');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('LangParent Artikel DE Zwei', $body['title']);
        self::assertIsArray($body['categories']);
        self::assertCount(1, $body['categories']);
        self::assertSame('Gamma', $body['categories'][0]['title']);
    }

    public function testTranslatedParentWithNoCategoriesReturnsEmptyArray(): void
    {
        $this->registerResources();

        // Article 902 has no DE translation and no categories.
        $response = $this->executeApiRequest('/de/api/lp-articles/902');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['categories']);
    }

    // ── German: uid preserved after overlay — MM key must be default uid ─────

    public function testTranslatedParentMmCategoryUidsAreDefaultLanguageUids(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lp-articles/900');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        $uids = array_column($body['categories'], 'uid');
        sort($uids);
        // UIDs must be 910 and 911 (default-language), not 960 and 961 (translations).
        self::assertSame([910, 911], $uids);
    }

    // ── German: collection endpoint ──────────────────────────────────────────

    public function testTranslatedParentCollectionOverlaysMmCategoriesForAllMembers(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lp-articles');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());

        $members = array_column($body['hydra:member'], null, 'uid');

        // Article 900 → DE title, German category titles
        self::assertSame('LangParent Artikel DE', $members[900]['title']);
        $titles900 = array_column($members[900]['categories'], 'title');
        self::assertContains('Alpha DE', $titles900);
        self::assertContains('Beta DE', $titles900);

        // Article 901 → DE title, fallback English category
        self::assertSame('LangParent Artikel DE Zwei', $members[901]['title']);
        self::assertCount(1, $members[901]['categories']);
        self::assertSame('Gamma', $members[901]['categories'][0]['title']);

        // Article 902 → no DE translation (fallback to EN), no categories
        self::assertSame('LangParent Article EN Empty', $members[902]['title']);
        self::assertSame([], $members[902]['categories']);
    }

    // ── IRI mode: MM relations return IRI strings in German context ──────────

    public function testTranslatedParentMmWithoutEmbedReturnsIriStrings(): void
    {
        $this->registerResources(embed: false);

        $response = $this->executeApiRequest('/de/api/lp-articles/900');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['categories']);
        self::assertCount(2, $body['categories']);

        foreach ($body['categories'] as $iri) {
            self::assertIsString($iri);
            self::assertMatchesRegularExpression('#/api/[^/]+/\d+$#', $iri);
        }
    }
}
