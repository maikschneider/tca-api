<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that the OpenAPI spec endpoint is hidden when tca_api.openApiExposed is false.
 */
final class SiteSettingsOpenApiHiddenTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_OpenApiHidden' => 'config/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    public function testOpenApiSpecReturns404WhenNotExposed(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testRegularApiEndpointsStillWorkWhenOpenApiHidden(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame(200, $response->getStatusCode());
    }
}
