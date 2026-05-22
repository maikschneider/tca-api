<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\SiteSettings;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that tca_api.allowedResources limits which resources are accessible.
 *
 * Site is configured with allowedResources: 'articles' — only articles are exposed.
 */
final class SiteSettingsAllowedResourcesTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_Restricted' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/colors.csv');
    }

    public function testAllowedResourceReturns200(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBlockedResourceReturns404(): void
    {
        $response = $this->executeApiRequest('/_api/colors');

        self::assertSame(404, $response->getStatusCode());
    }

    // ── Hydra docs do not leak blocked resource types ─────────────────────────

    public function testHydraDocsDoNotContainBlockedSupportedClass(): void
    {
        $response = $this->executeApiRequest('/_api/docs.jsonld');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);
        $classIds = array_column($body['hydra:supportedClass'], '@id');

        self::assertContains('#Article', $classIds, 'Article must be present');
        self::assertNotContains('#Color', $classIds, 'Color is blocked and must not appear in supportedClass');
    }

    public function testHydraDocsRelationRangeDoesNotPointToBlockedClass(): void
    {
        $response = $this->executeApiRequest('/_api/docs.jsonld');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeResponseBody($response);

        $articleClass = null;
        foreach ($body['hydra:supportedClass'] as $class) {
            if ($class['@id'] === '#Article') {
                $articleClass = $class;
                break;
            }
        }
        self::assertNotNull($articleClass, 'Article class must be present in docs');

        // color_id relates to the colors resource which is blocked — its range must not be #Color
        foreach ($articleClass['hydra:supportedProperty'] as $prop) {
            if ($prop['hydra:title'] === 'color_id') {
                $range = $prop['hydra:property']['range'] ?? null;
                self::assertNotSame('#Color', $range, 'color_id range must not reference the blocked Color class');
                return;
            }
        }
    }
}
