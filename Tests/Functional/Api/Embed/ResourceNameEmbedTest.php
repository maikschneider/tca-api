<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Embed;

use MaikSchneider\TcaApi\Registry\ApiRegistry;
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
        ApiRegistry::register('rn-colors-v1', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'rn-colors-v1',
                'resourceType' => 'ColorV1',
                'operations'   => ['list', 'show'],
                'itemsPerPage' => 20,
            ],
            'columns' => [
                'name' => ['groups' => ['list', 'show'], 'required' => false],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    /** Explicit mode: exposes name + hex. */
    private function registerColorsV2(): void
    {
        ApiRegistry::register('rn-colors-v2', [
            'general' => [
                'table'        => self::COLOR_TABLE,
                'resourceName' => 'rn-colors-v2',
                'resourceType' => 'ColorV2',
                'operations'   => ['list', 'show'],
                'itemsPerPage' => 20,
            ],
            'columns' => [
                'name' => ['groups' => ['list', 'show'], 'required' => false],
                'hex'  => ['groups' => ['list', 'show'], 'required' => false],
            ],
            'order' => ['allowed' => ['uid'], 'default' => ['uid' => 'asc']],
        ]);
    }

    /** Register the article resource; $colorIdOverride replaces the default color_id column config. */
    private function registerArticleResource(array $colorIdOverride = []): void
    {
        ApiRegistry::register('rn-articles', [
            'general' => [
                'table'        => self::ARTICLE_TABLE,
                'resourceName' => 'rn-articles',
                'resourceType' => 'Article',
                'operations'   => ['list', 'show'],
                'itemsPerPage' => 20,
            ],
            'columns' => array_merge([
                'title'    => ['groups' => ['list', 'show'], 'required' => false],
                'color_id' => ['groups' => ['list', 'show'], 'required' => false],
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
            'color_id' => ['groups' => ['list', 'show'], 'required' => false, 'embed' => true, 'resourceName' => 'rn-colors-v2'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('color', $body);
        self::assertIsArray($body['color']);
        self::assertArrayHasKey('hex', $body['color']);
    }

    public function testWithoutResourceNameOverrideFirstRegistrationIsUsed(): void
    {
        $this->registerColorsV1(); // only v1 — no hex column
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'required' => false, 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('name', $body['color']);
        self::assertArrayNotHasKey('hex', $body['color']);
    }

    public function testResourceNameColumnConfigSelectsV1ExplicitlyOmitsHex(): void
    {
        $this->registerColorsV1();
        $this->registerColorsV2(); // registered second — would NOT be returned by getByTable()
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'required' => false, 'embed' => true, 'resourceName' => 'rn-colors-v1'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertArrayNotHasKey('hex', $body['color']);
        self::assertSame('Red', $body['color']['name']);
    }

    public function testResourceNameColumnConfigSetsCorrectResourceType(): void
    {
        $this->registerColorsV1();
        $this->registerColorsV2();
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'required' => false, 'embed' => true, 'resourceName' => 'rn-colors-v2'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertSame('ColorV2', $body['color']['@type']);
        self::assertStringContainsString('rn-colors-v2', $body['color']['@id']);
    }

    // ── Group 2: No API definition + embed=true → default-mode serialization ──

    public function testNoApiDefinitionWithEmbedSerializesDefaultModeColumns(): void
    {
        // 'resourceName' points to a non-existent registry key → ApiRegistry::get() returns null
        // → buildDefaultConfig() synthesized → all TCA columns exposed (default mode: no 'groups' gate).
        // The default 'colors' registration only exposes 'name' (explicit mode); the synthesized config
        // exposes all columns including 'hex'.
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'required' => false, 'embed' => true, 'resourceName' => 'rn-colors-nonexistent'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('color', $body);
        self::assertIsArray($body['color']);
        self::assertArrayHasKey('name', $body['color']);
        self::assertArrayHasKey('hex', $body['color']);
        self::assertSame('Red', $body['color']['name']);
    }

    public function testNoApiDefinitionWithoutEmbedReturnsStub(): void
    {
        // 'resourceName' points to a non-existent key but embed is absent → depth=0 → stub returned.
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'required' => false, 'resourceName' => 'rn-colors-nonexistent'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('color', $body);
        self::assertArrayHasKey('@id', $body['color']);
        self::assertArrayHasKey('uid', $body['color']);
        self::assertArrayNotHasKey('name', $body['color']);
    }

    public function testNoApiDefinitionDefaultConfigUsesTableNameForJsonLd(): void
    {
        // buildDefaultConfig() uses $foreignTable as resourceType when no resourceType override given.
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'required' => false, 'embed' => true, 'resourceName' => 'rn-colors-nonexistent'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertSame(self::COLOR_TABLE, $body['color']['@type']);
    }

    public function testNoApiDefinitionNullFkReturnsNull(): void
    {
        // uid=52 has color_id=0 — serializeHasOne() returns null before registry lookup.
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'required' => false, 'embed' => true, 'resourceName' => 'rn-colors-nonexistent'],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/52');

        $body = $this->decodeResponseBody($response);
        self::assertNull($body['color']);
    }

    // ── Group 3: Regression — single registration still works ─────────────────

    public function testSingleRegistrationAutoSelectedForEmbed(): void
    {
        $this->registerColorsV1();
        $this->registerArticleResource([
            'color_id' => ['groups' => ['list', 'show'], 'required' => false, 'embed' => true],
        ]);

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertSame('Red', $body['color']['name']);
    }

    public function testNoEmbedReturnStubWithSingleRegistration(): void
    {
        $this->registerColorsV1();
        $this->registerArticleResource(); // no embed

        $response = $this->executeApiRequest('/_api/rn-articles/50');

        $body = $this->decodeResponseBody($response);
        self::assertArrayHasKey('color', $body);
        self::assertArrayNotHasKey('name', $body['color']);
        self::assertArrayHasKey('@id', $body['color']);
    }
}
