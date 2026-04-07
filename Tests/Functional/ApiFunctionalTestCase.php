<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional;

use Psr\Http\Message\ResponseInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

abstract class ApiFunctionalTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
    ];

    protected array $additionalFoldersToCreate = [
        'config',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites' => 'config/sites',
    ];

    /**
     * Execute a GET request against the API and return the PSR-7 response.
     */
    protected function executeApiRequest(string $path, array $queryParams = []): ResponseInterface
    {
        $uri = 'http://localhost' . $path;
        if ($queryParams !== []) {
            $uri .= '?' . http_build_query($queryParams);
        }

        return $this->executeFrontendSubRequest(new InternalRequest($uri));
    }

    /**
     * Decode the response body as JSON and return the array.
     */
    protected function decodeResponseBody(ResponseInterface $response): array
    {
        $body = (string)$response->getBody();
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
