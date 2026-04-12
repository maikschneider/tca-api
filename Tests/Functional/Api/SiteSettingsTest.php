<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies site-level settings that can be tested with the default site fixture.
 *
 * Covers: openApiExposed, defaultItemsPerPage, no CORS by default.
 */
final class SiteSettingsTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    public function testApiActiveWhenApiPrefixConfigured(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testOpenApiSpecExposedByDefault(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testNoCorsHeadersByDefault(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
