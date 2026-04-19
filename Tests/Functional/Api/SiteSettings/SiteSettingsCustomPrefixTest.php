<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\SiteSettings;

use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that a custom tca_api.apiPrefix is applied consistently to all
 * generated outbound links: collection @id, item @id, relation stubs, POST
 * Location headers, and userinfo @id.
 *
 * Site is configured with apiPrefix: '/custom-api/'.
 */
final class SiteSettingsCustomPrefixTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_CustomPrefix' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
    }

    // ── Routing smoke tests ───────────────────────────────────────────────────

    public function testCustomPrefixRoutesToApiAndReturnsHydraCollection(): void
    {
        $response = $this->executeApiRequest('/custom-api/articles');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hydra:Collection', $body['@type']);
    }

    public function testDefaultPrefixInactiveWithCustomPrefix(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        // Middleware only handles /custom-api/ — TYPO3 returns 404 for /_api/.
        self::assertSame(404, $response->getStatusCode());
    }

    // ── Collection @id ────────────────────────────────────────────────────────

    public function testCollectionIdUsesCustomPrefix(): void
    {
        $response = $this->executeApiRequest('/custom-api/articles');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('/custom-api/articles', $body['@id']);
        self::assertStringNotContainsString('/_api/', $body['@id']);
    }

    public function testCollectionPaginationLinksUseCustomPrefix(): void
    {
        $response = $this->executeApiRequest('/custom-api/articles');
        $body = $this->decodeResponseBody($response);

        $view = $body['hydra:view'];
        self::assertStringStartsWith('/custom-api/articles', $view['hydra:first']);
        self::assertStringStartsWith('/custom-api/articles', $view['hydra:last']);
    }

    // ── Item @id ──────────────────────────────────────────────────────────────

    public function testItemIdUsesCustomPrefix(): void
    {
        $response = $this->executeApiRequest('/custom-api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/custom-api/articles/1', $body['@id']);
        self::assertStringNotContainsString('/_api/', $body['@id']);
    }

    // ── Embedded relation stub @id ────────────────────────────────────────────

    public function testEmbeddedRelationStubIdUsesCustomPrefix(): void
    {
        // Article 1 has color_id=1 → serialized as a shallow stub for colors resource.
        $response = $this->executeApiRequest('/custom-api/articles/1');
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($body['color']);
        self::assertSame('/custom-api/colors/1', $body['color']['@id']);
        self::assertStringNotContainsString('/_api/', $body['color']['@id']);
    }

    // ── POST Location header ──────────────────────────────────────────────────

    public function testPostLocationHeaderUsesCustomPrefix(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/custom-api/articles', 1, [
            'title' => 'Prefix Test',
        ]);

        self::assertSame(201, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('/custom-api/articles/', $location);
        self::assertStringNotContainsString('/_api/', $location);
    }

    public function testPostResponseBodyIdUsesCustomPrefix(): void
    {
        $response = $this->executeApiWriteRequestAs('POST', '/custom-api/articles', 1, [
            'title' => 'Prefix Body',
        ]);
        $body = $this->decodeResponseBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertStringStartsWith('/custom-api/articles/', $body['@id']);
        self::assertStringNotContainsString('/_api/', $body['@id']);
    }

    // ── Userinfo @id ──────────────────────────────────────────────────────────

    public function testUserinfoIdUsesCustomPrefix(): void
    {
        ApiRegistry::register('me', [
            'general' => [
                'type'         => 'userinfo',
                'table'        => 'fe_users',
                'resourceName' => 'me',
                'resourceType' => 'FeUser',
            ],
            'columns' => [
                'username' => ['groups' => ['list', 'show']],
            ],
        ]);

        $response = $this->executeApiRequestAs('/custom-api/me', 1);
        $body = $this->decodeResponseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('/custom-api/me/', $body['@id']);
        self::assertStringNotContainsString('/_api/', $body['@id']);
    }
}
