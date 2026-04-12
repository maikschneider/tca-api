<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Validates that the Swagger UI endpoint is available and correctly configured when enabled in site settings.
 */
final class SwaggerUiTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
    }

    public function testSwaggerUi(): void
    {
        $response = $this->executeApiRequest('/_api/swagger-ui');
        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        self::assertStringContainsString('<div id="swagger-ui"></div>', $body);
    }

    public function testPostToSwaggerUiReturns405(): void
    {
        $response = $this->executeApiWriteRequest('POST', '/_api/swagger-ui');
        self::assertSame(405, $response->getStatusCode());
    }
}
