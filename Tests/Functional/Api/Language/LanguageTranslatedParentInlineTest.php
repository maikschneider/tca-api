<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Language;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for inline (foreign_field) relations when the parent record itself
 * is translated.
 *
 * tx_myext_domain_model_color has no language fields, so applyLanguageConstraintForTable
 * is a no-op and children are returned as-is. The key assertion is that the inline
 * children are still found: applyLanguageOverlay on the parent article must preserve the
 * default-language uid so the foreign_field lookup (WHERE foreign_article_id = defaultUid)
 * locates the correct child rows.
 *
 * Fixture data:
 *   Article 900 (EN) / 950 (DE, l18n_parent=900) → inline colors [20 LP Red, 21 LP Blue]
 *   Article 901 (EN) / 951 (DE, l18n_parent=901) → inline color  [22 LP Green]
 *   Article 902 (EN, no DE translation)           → no inline colors
 */
final class LanguageTranslatedParentInlineTest extends ApiFunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors_lang_parent.csv');
    }

    private function registerResources(): void
    {
        $this->registerResource('lp-inline-colors', [
            'general' => [
                'table'        => 'tx_myext_domain_model_color',
                'resourceName' => 'lp-inline-colors',
                'resourceType' => 'Color',
                'operations'   => ['list', 'show'],
            ],
            'columns' => ['name' => ['groups' => ['list', 'show']]],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        $this->registerResource('lp-inline-articles', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'lp-inline-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'title'                => ['groups' => ['list', 'show']],
                'related_items_inline' => ['groups' => ['list', 'show'], 'embed' => true],
            ],
            'security' => [
                'list' => AccessRole::PUBLIC,
                'show' => AccessRole::PUBLIC,
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── English baseline ─────────────────────────────────────────────────────

    public function testEnglishParentWithInlineChildrenReturnsChildren(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/api/lp-inline-articles/900');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('LangParent Article EN', $body['title']);
        self::assertCount(2, $body['related_items_inline']);

        $names = array_column($body['related_items_inline'], 'name');
        self::assertContains('LP Red', $names);
        self::assertContains('LP Blue', $names);
    }

    // ── German: translated parent → inline children still found ─────────────

    public function testTranslatedParentInlineChildrenStillFoundInGerman(): void
    {
        $this->registerResources();

        // Article 900 has a German translation (950). After overlay the uid is preserved
        // as 900. The inline query WHERE foreign_article_id=900 must find colors 20 + 21.
        $response = $this->executeApiRequest('/de/api/lp-inline-articles/900');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('LangParent Artikel DE', $body['title']);
        self::assertCount(2, $body['related_items_inline']);

        $names = array_column($body['related_items_inline'], 'name');
        self::assertContains('LP Red', $names);
        self::assertContains('LP Blue', $names);
    }

    public function testTranslatedParentSingleInlineChildInGerman(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lp-inline-articles/901');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('LangParent Artikel DE Zwei', $body['title']);
        self::assertCount(1, $body['related_items_inline']);
        self::assertSame('LP Green', $body['related_items_inline'][0]['name']);
    }

    public function testTranslatedParentNoInlineChildrenReturnsEmptyArray(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lp-inline-articles/902');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['related_items_inline']);
    }

    // ── German: collection endpoint ──────────────────────────────────────────

    public function testTranslatedParentCollectionInlineChildrenPreloadsCorrectly(): void
    {
        $this->registerResources();

        $response = $this->executeApiRequest('/de/api/lp-inline-articles');
        $body     = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());

        $members = array_column($body['hydra:member'], null, 'uid');

        self::assertSame('LangParent Artikel DE', $members[900]['title']);
        self::assertCount(2, $members[900]['related_items_inline']);

        self::assertSame('LangParent Artikel DE Zwei', $members[901]['title']);
        self::assertCount(1, $members[901]['related_items_inline']);

        self::assertSame([], $members[902]['related_items_inline']);
    }
}
