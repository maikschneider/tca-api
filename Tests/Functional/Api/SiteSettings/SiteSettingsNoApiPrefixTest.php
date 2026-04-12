<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\SiteSettings;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that the API is completely inactive when tca_api.apiPrefix is not configured.
 *
 * The site set is not applied, so no tca_api settings exist at all.
 * The middleware must short-circuit and pass every request through to TYPO3.
 */
final class SiteSettingsNoApiPrefixTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_NoApiPrefix' => 'config/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
    }

    public function testApiPassesThroughWithNoApiPrefixConfigured(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        // Middleware passes through — TYPO3 returns 404 (no page at /_api/).
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('hydra:Collection', (string)$response->getBody());
    }
}
