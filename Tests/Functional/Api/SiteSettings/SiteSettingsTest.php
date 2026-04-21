<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\SiteSettings;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Verifies site-level settings that can be tested with the default site fixture.
 *
 * Covers: openApiExposed, defaultItemsPerPage, no CORS by default, OPTIONS without CORS.
 */
final class SiteSettingsTest extends ApiFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
    }

    public function testApiActiveWithNoCorsHeadersByDefault(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testOpenApiSpecExposedByDefault(): void
    {
        $response = $this->executeApiRequest('/_api/openapi.json');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testOptionsReturns405WhenCorsDisabled(): void
    {
        $request = (new InternalRequest('http://localhost/_api/articles'))
            ->withMethod('OPTIONS');

        $response = $this->executeFrontendSubRequest($request);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
