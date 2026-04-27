<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\SiteSettings;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that tca_api.allowedResources filtering is applied to the OpenAPI spec.
 *
 * Site is configured with allowedResources: 'articles' — only articles should appear in the spec.
 */
final class SiteSettingsAllowedResourcesOpenApiTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_Restricted' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
    }

    public function testOpenApiSpecOnlyContainsAllowedResources(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        $paths = array_keys($body['paths'] ?? []);

        // 'articles' is allowed — its paths must exist
        self::assertContains('/_api/articles', $paths);

        // 'colors' is not in allowedResources — must be absent from spec
        $colorPaths = array_filter($paths, static fn (string|int $path): bool => str_contains((string)$path, 'colors'));
        self::assertSame([], $colorPaths, 'Blocked resource "colors" should not appear in OpenAPI spec');
    }

    public function testOpenApiSpecSchemasOnlyContainAllowedResources(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');
        $body = $this->decodeResponseBody($response);
        $schemaNames = array_keys($body['components']['schemas'] ?? []);

        // Article schemas should be present
        self::assertContains('ArticleRead', $schemaNames);
        self::assertContains('ArticleWrite', $schemaNames);

        // Color schemas should be absent
        self::assertNotContains('ColorRead', $schemaNames);
        self::assertNotContains('ColorWrite', $schemaNames);
    }
}
