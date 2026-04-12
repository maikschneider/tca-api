<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that the API middleware passes through when tca_api.enabled is false.
 */
final class SiteSettingsDisabledTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_Disabled' => 'config/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    public function testApiPathPassesThroughWhenDisabled(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        // Middleware passes through — TYPO3 resolves the path and returns 404 (no page at /_api/).
        self::assertSame(404, $response->getStatusCode());
    }

    public function testResponseHasNoHydraCollectionTypeWhenDisabled(): void
    {
        $response = $this->executeApiRequest('/_api/articles');
        $body = (string)$response->getBody();

        self::assertStringNotContainsString('hydra:Collection', $body);
    }
}
