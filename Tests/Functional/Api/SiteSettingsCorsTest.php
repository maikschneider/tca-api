<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * Verifies that CORS headers are added when tca_api.corsEnabled is true.
 */
final class SiteSettingsCorsTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_Cors' => 'config/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/articles.csv');
    }

    public function testCorsOriginHeaderPresentWhenEnabled(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCorsMethodsHeaderPresentWhenEnabled(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertNotSame('', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testCorsHeadersPresentOnErrorResponses(): void
    {
        $response = $this->executeApiRequest('/_api/nonexistent-resource');

        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
