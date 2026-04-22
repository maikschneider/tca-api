<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\SiteSettings;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Verifies that CORS headers are added when tca_api.corsEnabled is true,
 * including explicit OPTIONS preflight handling.
 */
final class SiteSettingsCorsTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_Cors' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
    }

    public function testCorsHeadersPresentWhenEnabled(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertNotSame('', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testCorsHeadersPresentOnErrorResponses(): void
    {
        $response = $this->executeApiRequest('/_api/nonexistent-resource');

        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testCorsAllowCredentialsHeader(): void
    {
        $response = $this->executeApiRequest('/_api/articles');

        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testOptionsPreflightReturns204WithCorsHeaders(): void
    {
        $request = (new InternalRequest('http://localhost/_api/articles'))
            ->withMethod('OPTIONS');

        $response = $this->executeFrontendSubRequest($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
        self::assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
        self::assertSame('', (string)$response->getBody());
    }

    public function testOptionsPreflightWorksForAnyApiPath(): void
    {
        $request = (new InternalRequest('http://localhost/_api/nonexistent-resource'))
            ->withMethod('OPTIONS');

        $response = $this->executeFrontendSubRequest($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
