<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Security;

use MaikSchneider\ApiKey\Authentication\ApiKeyAuthenticatorInterface;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\RequiresMethod;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * The api_key bridge allows `X-API-Key` in CORS preflights without a
 * `tca_api.corsAllowHeaders` entry.
 */
#[RequiresMethod(ApiKeyAuthenticatorInterface::class, 'authenticate')]
final class ApiKeyCorsTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        'maikschneider/api-key',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites_Cors_Wildcard' => 'typo3conf/sites',
    ];

    public function testPreflightAllowsTheApiKeyHeader(): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest('http://localhost/_api/articles'))
                ->withMethod('OPTIONS')
                ->withAddedHeader('Access-Control-Request-Headers', 'x-api-key'),
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('Content-Type, Authorization, X-Locale, X-API-Key', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }
}
