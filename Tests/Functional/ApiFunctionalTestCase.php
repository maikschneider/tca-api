<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Loader\ApiDefinitionLoader;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class ApiFunctionalTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Reload baseline definitions (idempotent — overwrites any stale entries).
        $this->get(ApiDefinitionLoader::class)->load();
    }

    /**
     * Return the DI-managed ApiRegistry instance.
     */
    protected function getApiRegistry(): ApiRegistry
    {
        return $this->get(ApiRegistry::class);
    }

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
     * Execute a GET request as a specific frontend user.
     */
    protected function executeApiRequestAs(string $path, int $feUserId, array $queryParams = []): ResponseInterface
    {
        $uri = 'http://localhost' . $path;
        if ($queryParams !== []) {
            $uri .= '?' . http_build_query($queryParams);
        }

        return $this->executeFrontendSubRequest(
            new InternalRequest($uri),
            (new InternalRequestContext())->withFrontendUserId($feUserId),
        );
    }

    /**
     * Execute a write request as a specific frontend user.
     */
    protected function executeApiWriteRequestAs(string $method, string $path, int $feUserId, array $data = []): ResponseInterface
    {
        $uri = 'http://localhost' . $path;

        $body = new Stream('php://temp', 'rw');
        if ($data !== []) {
            $body->write(json_encode($data, JSON_THROW_ON_ERROR));
            $body->rewind();
        }

        return $this->executeFrontendSubRequest(
            (new InternalRequest($uri))
                ->withMethod($method)
                ->withAddedHeader('Content-Type', 'application/json')
                ->withBody($body),
            (new InternalRequestContext())->withFrontendUserId($feUserId),
        );
    }

    /**
     * Execute a write request as a backend user.
     */
    protected function executeApiWriteRequestAsBackendUser(string $method, string $path, int $beUserId, array $data = []): ResponseInterface
    {
        $uri = 'http://localhost' . $path;

        $body = new Stream('php://temp', 'rw');
        if ($data !== []) {
            $body->write(json_encode($data, JSON_THROW_ON_ERROR));
            $body->rewind();
        }

        return $this->executeFrontendSubRequest(
            (new InternalRequest($uri))
                ->withMethod($method)
                ->withAddedHeader('Content-Type', 'application/json')
                ->withBody($body),
            (new InternalRequestContext())->withBackendUserId($beUserId),
        );
    }

    /**
     * Execute a GET request as a backend user.
     */
    protected function executeApiRequestAsBackendUser(string $path, int $beUserId, array $queryParams = []): ResponseInterface
    {
        $uri = 'http://localhost' . $path;
        if ($queryParams !== []) {
            $uri .= '?' . http_build_query($queryParams);
        }

        return $this->executeFrontendSubRequest(
            new InternalRequest($uri),
            (new InternalRequestContext())->withBackendUserId($beUserId),
        );
    }

    /**
     * Execute a write request with a raw (potentially non-JSON) body as a frontend user.
     */
    protected function executeApiWriteRawRequestAs(string $method, string $path, int $feUserId, string $rawBody): ResponseInterface
    {
        $uri = 'http://localhost' . $path;

        $body = new Stream('php://temp', 'rw');
        $body->write($rawBody);
        $body->rewind();

        return $this->executeFrontendSubRequest(
            (new InternalRequest($uri))
                ->withMethod($method)
                ->withAddedHeader('Content-Type', 'application/json')
                ->withBody($body),
            (new InternalRequestContext())->withFrontendUserId($feUserId),
        );
    }

    /**
     * Execute a write request (POST/PUT/PATCH/DELETE) with a JSON body.
     */
    protected function executeApiWriteRequest(string $method, string $path, array $data = []): ResponseInterface
    {
        $uri = 'http://localhost' . $path;

        $body = new Stream('php://temp', 'rw');
        if ($data !== []) {
            $body->write(json_encode($data, JSON_THROW_ON_ERROR));
            $body->rewind();
        }

        return $this->executeFrontendSubRequest(
            (new InternalRequest($uri))
                ->withMethod($method)
                ->withAddedHeader('Content-Type', 'application/json')
                ->withBody($body),
        );
    }

    /**
     * Normalise a raw config array and register it in ApiRegistry.
     * Use this in tests instead of accessing the registry directly.
     */
    protected function registerResource(string $name, array $rawConfig): void
    {
        $this->getApiRegistry()->register($name, ApiDefinition::fromArray($rawConfig));
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
