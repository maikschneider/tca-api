<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Functional tests for two embed serialization features:
 *
 * Feature 1 — resourceName column config:
 *   When a table has multiple ApiRegistry registrations, the 'resourceName' key in a column's
 *   embed config selects a specific registration by name instead of the first-registered default.
 *
 * Feature 2 — No-registration + embed=true:
 *   When no API definition exists for a related table but 'embed' => true is set, a minimal
 *   default-mode config is synthesized (all TCA columns, no groups gate) so the record is
 *   fully serialized without requiring a registered endpoint.
 *
 * Fixtures used: pages.csv + colors.csv + articles_embed.csv
 *   Article uid=50 → color_id=1 (Red)
 *   Article uid=52 → color_id=0 (no color)
 */
final class ResourceNameEmbedTest extends ApiFunctionalTestCase
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

    /** Explicit mode: exposes name only. */
    private function registerColorsV1(): void
    {
        $this->registerResource('rn-colors-v1', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'rn-colors-v1',
                'resourceType' => 'ColorV1',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'name' => ['groups' => ['list', 'show']],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    /** Explicit mode: exposes name + hex. */
    private function registerColorsV2(): void
    {
        $this->registerResource('rn-colors-v2', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'rn-colors-v2',
                'resourceType' => 'ColorV2',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'name' => ['groups' => ['list', 'show']],
                'hex'  => ['groups' => ['list', 'show']],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    /** Register the article resource; $colorIdOverride replaces the default color_id column config. */
    private function registerArticleResource(array $colorIdOverride = []): void
    {
        $this->registerResource('rn-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'rn-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => array_merge([
                'title'    => ['groups' => ['list', 'show']],
                'color_id' => ['groups' => ['list', 'show']],
            ], $colorIdOverride),
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    // ── Group 1: resourceName column config selects specific ApiRegistry entry ─

    public function testResourceNameColumnConfigSelectsV2HasHex(): void
    {
        $this->registerColorsV1(); // registered first — would be returned by getByTable() without override
        $this->registerColorsV2();
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true, 'resourceName' => 'rn-colors-v2'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('color_id', $body);
        self::assertIsArray($body['color_id']);
        self::assertArrayHasKey('hex', $body['color_id']);
    }

    public function testWithoutResourceNameOverrideFirstRegistrationIsUsed(): void
    {
        $this->registerColorsV1(); // only v1 — no hex column
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('name', $body['color_id']);
        self::assertArrayNotHasKey('hex', $body['color_id']);
    }

    public function testResourceNameColumnConfigSelectsV1ExplicitlyOmitsHex(): void
    {
        $this->registerColorsV1();
        $this->registerColorsV2(); // registered second — would NOT be returned by getByTable()
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true, 'resourceName' => 'rn-colors-v1'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertArrayNotHasKey('hex', $body['color_id']);
        self::assertSame('Red', $body['color_id']['name']);
    }

    public function testResourceNameColumnConfigSetsCorrectResourceType(): void
    {
        $this->registerColorsV1();
        $this->registerColorsV2();
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true, 'resourceName' => 'rn-colors-v2'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertSame('ColorV2', $body['color_id']['@type']);
        self::assertStringContainsString('rn-colors-v2', $body['color_id']['@id']);
    }

    // ── Group 2: No API definition + embed=true → default-mode serialization ──

    public function testNoApiDefinitionWithEmbedSerializesDefaultModeColumns(): void
    {
        // 'resourceName' points to a non-existent registry key → ApiRegistry::get() returns null
        // → buildDefaultConfig() synthesized → all TCA columns exposed (default mode: no 'groups' gate).
        // The default 'colors' registration only exposes 'name' (explicit mode); the synthesized config
        // exposes all columns including 'hex'.
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true, 'resourceName' => 'rn-colors-nonexistent'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('color_id', $body);
        self::assertIsArray($body['color_id']);
        self::assertArrayHasKey('name', $body['color_id']);
        self::assertArrayHasKey('hex', $body['color_id']);
        self::assertSame('Red', $body['color_id']['name']);
    }

    public function testNoApiDefinitionWithoutEmbedReturnsIri(): void
    {
        // 'resourceName' points to a non-existent key but embed is absent → depth=0 → IRI string returned.
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'resourceName' => 'rn-colors-nonexistent'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('color_id', $body);
        self::assertIsString($body['color_id']);
        self::assertStringContainsString('/rn-colors-nonexistent/1', $body['color_id']);
    }

    public function testNoApiDefinitionDefaultConfigUsesTableNameForJsonLd(): void
    {
        // buildDefaultConfig() uses $foreignTable as resourceType when no resourceType override given.
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true, 'resourceName' => 'rn-colors-nonexistent'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertSame(self::COLOR_TABLE, $body['color_id']['@type']);
    }

    public function testNoApiDefinitionNullFkReturnsNull(): void
    {
        // uid=52 has color_id=0 — serializeHasOne() returns null before registry lookup.
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true, 'resourceName' => 'rn-colors-nonexistent'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/52');

        $body = $this->decodeResponseBody($response);
        self::assertNull($body['color_id']);
    }

    // ── Group 3: Regression — single registration still works ─────────────────

    public function testSingleRegistrationAutoSelectedForEmbed(): void
    {
        $this->registerColorsV1();
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertSame('Red', $body['color_id']['name']);
    }

    public function testNoEmbedReturnIriWithSingleRegistration(): void
    {
        $this->registerColorsV1();
        $this->registerArticleResource(); // no embed

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('color_id', $body);
        self::assertIsString($body['color_id']);
        self::assertStringEndsWith('/1', $body['color_id']);
    }

    // ── Group 4: HasMany default-mode fallback (covers serializeHasManyFromRows) ──

    public function testNoApiDefinitionHasManyWithEmbedSerializesDefaultModeColumns(): void
    {
        // Article 200 has related_colors="1,2" (type=group, single table, UID list in own column).
        // 'resourceName' points to a non-existent key → buildDefaultConfig() synthesized →
        // all TCA columns exposed (name + hex) without a registered color endpoint.
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_group.csv');

        $this->registerResource('rn-group-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'rn-group-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
            ],
            'columns' => [
                'title'          => ['groups' => ['list', 'show']],
                'related_colors' => ['groups' => ['list', 'show'], 'embed' => true, 'resourceName' => 'rn-colors-nonexistent'],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);

        $response = $this->executeApiRequest('/_api/rn-group-articles/200');

        $body = $this->decodeResponseBody($response);
        self::assertIsArray($body['related_colors']);
        self::assertCount(2, $body['related_colors']);
        self::assertArrayHasKey('name', $body['related_colors'][0]);
        self::assertArrayHasKey('hex', $body['related_colors'][0]);
        self::assertSame('Red', $body['related_colors'][0]['name']);
    }
}
