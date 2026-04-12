<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\SiteSettings;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that a custom tca_api.apiPrefix routes requests correctly.
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
    }

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
}
