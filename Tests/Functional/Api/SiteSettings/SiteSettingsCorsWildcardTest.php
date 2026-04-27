<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\SiteSettings;

use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Verifies the wildcard origin + credentials fix: when corsOrigin is '*' and
 * corsAllowCredentials is true, the middleware must reflect the request's Origin
 * header instead of emitting the invalid '*' value.
 */
final class SiteSettingsCorsWildcardTest extends ApiFunctionalTestCase
{
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_Cors_Wildcard' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles.csv');
    }

    public function testWildcardOriginIsReflectedWhenCredentialsEnabled(): void
    {
        $request = (new InternalRequest('http://localhost/_api/articles'))
            ->withAddedHeader('Origin', 'https://my-app.example.com');

        $response = $this->executeFrontendSubRequest($request);

        self::assertSame('https://my-app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testPreflightReflectsOriginWhenWildcardAndCredentials(): void
    {
        $request = (new InternalRequest('http://localhost/_api/articles'))
            ->withMethod('OPTIONS')
            ->withAddedHeader('Origin', 'https://my-app.example.com');

        $response = $this->executeFrontendSubRequest($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://my-app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testWildcardFallsBackWhenNoOriginHeader(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('http://localhost/_api/articles'));

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
