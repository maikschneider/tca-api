<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\SiteSettings;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that the SwaggerUi endpoint is hidden when tca_api.swaggerUiEnabled is NONE.
 */
final class SiteSettingsSwaggerUiHiddenTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_SwaggerUiHidden' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
    }

    public function testSwaggerUiReturns404WhenNotExposed(): void
    {
        $response = $this->executeApiRequest('/_api/swagger-ui');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testRegularApiEndpointsStillWorkWhenSwaggerUiHidden(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame(200, $response->getStatusCode());
    }
}
