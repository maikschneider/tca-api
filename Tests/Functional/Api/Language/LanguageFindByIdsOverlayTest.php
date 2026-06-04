<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * RED tests for issue #135: language overlay missing on findByIds paths.
 *
 * Five relation paths skip language overlay because they resolve UIDs via
 * DataRepository::findByIds() which has no language awareness. These tests
 * assert the CORRECT (overlaid) behaviour and are expected to FAIL until the
 * fix (findByIdsWithOverlay) is in place.
 *
 * Affected paths tested here:
 *   1. hasOne FK embed              — article.color_id → translatable color
 *   2. UID-list hasMany             — article.related_colors (type=group, no MM)
 *   3. type=group single-table, no MM — same column/path as path 2
 *   4. type=group multi-table       — article.related_items (color + article)
 *   5. Reverse-MM forward-side      — category.items → articles via MM_oppositeUsage
 *
 * Fixture data:
 *   Color 970 "FBU Color One EN"    → DE translation 975 "FBU Color One DE"
 *   Color 971 "FBU Color Two EN"    → DE translation 976 "FBU Color Two DE"
 *   Color 972 "FBU Color Three EN"  → NO DE translation (fallback expected)
 *
 *   Article 980 (EN only) color_id=970, related_colors="970,971",
 *               related_items="tx_myext_domain_model_color_970,tx_myext_domain_model_article_984"
 *   Article 981 (EN only) color_id=971, related_colors="971,972"
 *   Article 982 (EN only) color_id=972
 *   Article 983 (EN only) "FBU Reverse Article EN One"  — no DE translation
 *   Article 984 (EN only) "FBU Reverse Article EN Two"  — HAS DE translation
 *   Article 987 (DE, l18n_parent=984) "FBU Reverse Article DE Two"
 *
 *   Category 920 (EN) → DE translation 923
 *       reverse-MM items: articles 983 (sf=1), 984 (sf=2)
 *   Category 921 (EN, no DE)
 *       reverse-MM items: article 984 (sf=1)
 */
final class LanguageFindByIdsOverlayTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_MultiLanguage' => 'typo3conf/sites',
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/fileadmin/user_upload' => 'fileadmin/user_upload',
    ];

    protected function setUp(): void
    {
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

        parent::setUp();

        $this->get(TcaSchemaFactory::class)->load($GLOBALS['TCA'], true);

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors_lang_findbyids.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_lang_findbyids.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_categories_lang_findbyids.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm_lang_findbyids.csv');
    }

    private function registerArticleResource(): void
    {
        $this->registerResource('fbu-articles', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'fbu-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'title'          => ['groups' => ['list', 'show']],
                'color_id'       => ['groups' => ['list', 'show'], 'embed' => true],
                'related_colors' => ['groups' => ['list', 'show'], 'embed' => true],
                'related_items'  => ['groups' => ['list', 'show'], 'embed' => true],
            ],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerColorResource(): void
    {
        $this->registerResource('fbu-colors', [
            'general' => [
                'table'        => 'tx_myext_domain_model_color',
                'resourceName' => 'fbu-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'name' => ['groups' => ['list', 'show']],
            ],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    private function registerCategoryResource(): void
    {
        $this->registerResource('fbu-categories', [
            'general' => [
                'table'        => 'sys_category',
                'resourceName' => 'fbu-categories',
                'resourceType' => 'Category',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'title' => ['groups' => ['list', 'show']],
                'items' => ['groups' => ['list', 'show'], 'embed' => true],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── English baseline — verify fixtures load correctly ────────────────────

    public function testEnglishHasOneFkReturnsEnglishColor(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/api/fbu-articles/980');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['color_id']);
        self::assertSame('FBU Color One EN', $body['color_id']['name']);
    }

    public function testEnglishUidListHasManyReturnsEnglishColors(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/api/fbu-articles/980');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        $names = array_column($body['related_colors'], 'name');
        self::assertContains('FBU Color One EN', $names);
        self::assertContains('FBU Color Two EN', $names);
    }

    public function testEnglishGroupMultiTableReturnsEnglishRecords(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/api/fbu-articles/980');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['related_items']);
    }

    public function testEnglishReverseMmItemsReturnsItems(): void
    {
        $this->registerArticleResource();
        $this->registerCategoryResource();

        $response = $this->executeApiRequest('/api/fbu-categories/920');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['items']);
    }

    // ── Path 1: hasOne FK embed — must overlay color to German ───────────────

    public function testHasOneFkEmbedOverlaysColorToGerman(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        // Article 980 (EN, no DE) → color_id=970 → should be overlaid to 975 "FBU Color One DE"
        $response = $this->executeApiRequest('/de/api/fbu-articles/980');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['color_id']);
        self::assertSame('FBU Color One DE', $body['color_id']['name']);
    }

    public function testHasOneFkEmbedFallsBackWhenColorHasNoTranslation(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        // Article 982 color_id=972 → color 972 has no DE translation → fallback to EN
        $response = $this->executeApiRequest('/de/api/fbu-articles/982');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['color_id']);
        self::assertSame('FBU Color Three EN', $body['color_id']['name']);
    }

    public function testHasOneFkEmbedPreservesDefaultLanguageUid(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/de/api/fbu-articles/980');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        // UID must stay at default-language value (970), not translation UID (975)
        self::assertSame(970, $body['color_id']['uid']);
    }

    // ── Path 2+3: UID-list hasMany / type=group single-table, no MM ─────────

    public function testUidListHasManyOverlaysColorsToGerman(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        // Article 980 related_colors="970,971" → both overlaid to DE
        $response = $this->executeApiRequest('/de/api/fbu-articles/980');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['related_colors']);
        $names = array_column($body['related_colors'], 'name');
        self::assertContains('FBU Color One DE', $names);
        self::assertContains('FBU Color Two DE', $names);
    }

    public function testUidListHasManyFallsBackForUntranslatedColor(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        // Article 981 related_colors="971,972" → 971→976 (DE), 972→fallback EN
        $response = $this->executeApiRequest('/de/api/fbu-articles/981');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['related_colors']);
        $names = array_column($body['related_colors'], 'name');
        self::assertContains('FBU Color Two DE', $names);
        self::assertContains('FBU Color Three EN', $names);
    }

    public function testUidListHasManyPreservesDefaultLanguageUids(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/de/api/fbu-articles/980');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        $uids = array_column($body['related_colors'], 'uid');
        sort($uids);
        // Must be default-language UIDs 970 + 971, not translation UIDs 975 + 976
        self::assertSame([970, 971], $uids);
    }

    // ── Path 4: type=group multi-table — must overlay each table ────────────

    public function testGroupMultiTableOverlaysColorAndArticleToGerman(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        // Article 980 related_items="tx_myext_domain_model_color_970,tx_myext_domain_model_article_984"
        // Color 970 → DE 975 "FBU Color One DE"
        // Article 984 → DE 987 "FBU Reverse Article DE Two"
        $response = $this->executeApiRequest('/de/api/fbu-articles/980');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['related_items']);

        $byUid = [];
        foreach ($body['related_items'] as $item) {
            $byUid[$item['uid']] = $item;
        }

        // Color 970 → overlaid to German, uid preserved
        self::assertArrayHasKey(970, $byUid);
        self::assertSame('FBU Color One DE', $byUid[970]['name']);

        // Article 984 → overlaid to German, uid preserved
        self::assertArrayHasKey(984, $byUid);
        self::assertSame('FBU Reverse Article DE Two', $byUid[984]['title']);
    }

    public function testGroupMultiTableCollectionOverlaysAllMembers(): void
    {
        $this->registerColorResource();
        $this->registerArticleResource();

        $response = $this->executeApiRequest('/de/api/fbu-articles');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        $byUid = array_column($body['hydra:member'], null, 'uid');

        // Article 980: related_items has color 970 and article 984
        $items = array_column($byUid[980]['related_items'], null, 'uid');
        self::assertSame('FBU Color One DE', $items[970]['name']);
        self::assertSame('FBU Reverse Article DE Two', $items[984]['title']);
    }

    // ── Path 5: Reverse-MM forward-side — articles must be overlaid ─────────

    public function testReverseMmForwardSideOverlaysArticlesToGerman(): void
    {
        $this->registerArticleResource();
        $this->registerCategoryResource();

        // Category 920 → reverse-MM items: articles 983 (no DE) + 984 (DE: 987)
        $response = $this->executeApiRequest('/de/api/fbu-categories/920');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['items']);

        $titles = array_column($body['items'], 'title');
        // Article 983 has no DE → fallback to EN
        self::assertContains('FBU Reverse Article EN One', $titles);
        // Article 984 has DE 987 → overlaid
        self::assertContains('FBU Reverse Article DE Two', $titles);
    }

    public function testReverseMmForwardSideSingleArticleOverlaidToGerman(): void
    {
        $this->registerArticleResource();
        $this->registerCategoryResource();

        // Category 921 → article 984 (has DE 987 "FBU Reverse Article DE Two")
        $response = $this->executeApiRequest('/de/api/fbu-categories/921');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['items']);
        self::assertSame('FBU Reverse Article DE Two', $body['items'][0]['title']);
    }

    public function testReverseMmForwardSidePreservesDefaultLanguageUids(): void
    {
        $this->registerArticleResource();
        $this->registerCategoryResource();

        $response = $this->executeApiRequest('/de/api/fbu-categories/920');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        $uids = array_column($body['items'], 'uid');
        sort($uids);
        // UIDs must be default-language UIDs 983 + 984, not translation UID 987
        self::assertSame([983, 984], $uids);
    }

    public function testReverseMmForwardSideCollectionOverlaysAllCategories(): void
    {
        $this->registerArticleResource();
        $this->registerCategoryResource();

        $response = $this->executeApiRequest('/de/api/fbu-categories');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        $byUid = array_column($body['hydra:member'], null, 'uid');

        // Category 920 → 2 items
        self::assertCount(2, $byUid[920]['items']);
        $titles920 = array_column($byUid[920]['items'], 'title');
        self::assertContains('FBU Reverse Article EN One', $titles920);
        self::assertContains('FBU Reverse Article DE Two', $titles920);

        // Category 921 → 1 item, overlaid
        self::assertCount(1, $byUid[921]['items']);
        self::assertSame('FBU Reverse Article DE Two', $byUid[921]['items'][0]['title']);
    }
}
