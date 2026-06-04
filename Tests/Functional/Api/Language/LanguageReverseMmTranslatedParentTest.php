<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Functional tests for reverse-side MM relations when the parent category is translated.
 *
 * sys_category has native language support (sys_language_uid + l10n_parent). When a
 * category is requested in German, applyLanguageOverlay merges the translation row but
 * preserves the default-language uid. The subsequent reverse-MM lookup
 * (WHERE uid_local IN (defaultUid)) must still find the items stored under that uid.
 *
 * Fixture data:
 *   Category 910 (EN) "Reverse Alpha"        → DE translation 960 "Reverse Alpha DE"
 *   Category 911 (EN) "Reverse Beta"         → no DE translation (fallback expected)
 *   Category 912 (EN) "Reverse Gamma (empty)"→ no items
 *
 *   MM entries (uid_local = category, uid_foreign = article):
 *     Category 910 → articles [2 (sf=1), 1 (sf=2)]
 *     Category 911 → article  [1 (sf=1)]
 *     Category 912 → (none)
 */
final class LanguageReverseMmTranslatedParentTest extends ApiFunctionalTestCase
{
    private const CATEGORY_TABLE = 'sys_category';
    private const ARTICLE_TABLE  = 'tx_myext_domain_model_article';

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiLanguage' => 'typo3conf/sites',
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/fileadmin/user_upload' => 'fileadmin/user_upload',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Register the wildcard reverse-MM column on sys_category.
        $GLOBALS['TCA']['sys_category']['columns']['items'] = [
            'label'  => 'Items',
            'config' => [
                'type'             => 'group',
                'allowed'          => '*',
                'MM'               => 'sys_category_record_mm',
                'MM_oppositeUsage' => [
                    'tx_myext_domain_model_article' => ['categories'],
                ],
                'size'             => 10,
                'maxitems'         => 9999,
            ],
        ];

        $this->get(TcaSchemaFactory::class)->load($GLOBALS['TCA'], true);

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_reverse_lang.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm_reverse_lang.csv');
    }

    private function registerCategoryResource(array $columnOverrides = []): void
    {
        $this->registerResource('rv-lang-categories', [
            'general' => [
                'table'        => self::CATEGORY_TABLE,
                'resourceName' => 'rv-lang-categories',
                'resourceType' => 'Category',
                'operations'   => ['list', 'show'],
            ],
            'columns' => array_merge([
                'title' => ['groups' => ['list', 'show']],
                'items' => ['groups' => ['list', 'show']],
            ], $columnOverrides),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerArticleResource(): void
    {
        $this->registerResource('rv-lang-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'rv-lang-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => ['title' => ['groups' => ['list', 'show']]],
            'order'   => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── English baseline ─────────────────────────────────────────────────────

    public function testEnglishParentReverseMmReturnsItems(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/api/rv-lang-categories/910');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Reverse Alpha', $body['title']);
        self::assertCount(2, $body['items']);
    }

    // ── German: translated parent → items still found ────────────────────────

    public function testTranslatedParentReverseMmItemsStillFoundInGerman(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();

        // Category 910 has a German translation (960). After overlay the uid is preserved
        // as 910. The reverse-MM lookup WHERE uid_local IN (910) must find articles 2 + 1.
        $response = $this->executeApiRequest('/de/api/rv-lang-categories/910');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Reverse Alpha DE', $body['title']);
        self::assertIsArray($body['items']);
        self::assertCount(2, $body['items']);

        foreach ($body['items'] as $iri) {
            self::assertIsString($iri);
            self::assertMatchesRegularExpression('#/api/[^/]+/\d+$#', $iri);
        }
    }

    public function testTranslatedParentReverseMmItemsOrderedBySortingForeign(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/de/api/rv-lang-categories/910');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        // sorting_foreign: article 2 → sf=1, article 1 → sf=2
        // IRI uses the first registered resource for tx_myext_domain_model_article (articles).
        self::assertStringEndsWith('articles/2', $body['items'][0]);
        self::assertStringEndsWith('articles/1', $body['items'][1]);
    }

    public function testUntranslatedParentFallsBackToEnglishTitleButItemsStillFound(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();

        // Category 911 has no German translation → fallback to English title.
        // Its items must still be returned (uid preserved through fallback path too).
        $response = $this->executeApiRequest('/de/api/rv-lang-categories/911');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Reverse Beta', $body['title']);
        self::assertCount(1, $body['items']);
    }

    public function testTranslatedParentEmptyReverseMmReturnsEmptyArray(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/de/api/rv-lang-categories/912');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['items']);
    }

    // ── German: embedded items ────────────────────────────────────────────────

    public function testTranslatedParentReverseMmWithEmbedReturnsFullRecords(): void
    {
        $this->registerCategoryResource([
            'items' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/de/api/rv-lang-categories/910');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Reverse Alpha DE', $body['title']);
        self::assertCount(2, $body['items']);

        $uids = array_column($body['items'], 'uid');
        self::assertContains(1, $uids);
        self::assertContains(2, $uids);
    }

    // ── German: collection endpoint ──────────────────────────────────────────

    public function testTranslatedParentCollectionReverseMmReturnsCorrectItemCounts(): void
    {
        $this->registerCategoryResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/de/api/rv-lang-categories');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());

        $byUid = [];
        foreach ($body['hydra:member'] as $cat) {
            $byUid[$cat['uid']] = $cat;
        }

        // Category 910 → title overlaid, 2 items
        self::assertSame('Reverse Alpha DE', $byUid[910]['title']);
        self::assertCount(2, $byUid[910]['items']);

        // Category 911 → fallback English title, 1 item
        self::assertSame('Reverse Beta', $byUid[911]['title']);
        self::assertCount(1, $byUid[911]['items']);

        // Category 912 → no items
        self::assertCount(0, $byUid[912]['items']);
    }
}
