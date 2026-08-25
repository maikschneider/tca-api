<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Loader\ApiDefinitionLoader;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\UploadedFile;
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

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/tca_api/Tests/Functional/Fixtures/fileadmin/user_upload' => 'fileadmin/user_upload',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Static backing store persists across DI containers; reset before
        // reloading baseline definitions to prevent cross-test leakage.
        $this->getApiRegistry()->reset();
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
     * Execute a GET request with arbitrary HTTP headers.
     *
     * @param array<string, string> $headers
     */
    protected function executeApiRequestWithHeaders(string $path, array $headers = [], array $queryParams = []): ResponseInterface
    {
        $uri = 'http://localhost' . $path;
        if ($queryParams !== []) {
            $uri .= '?' . http_build_query($queryParams);
        }

        $request = new InternalRequest($uri);
        foreach ($headers as $name => $value) {
            $request = $request->withAddedHeader($name, $value);
        }

        return $this->executeFrontendSubRequest($request);
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
     * Execute a multipart/form-data write request as a specific frontend user.
     *
     * @param array<string, string>                          $fields   Scalar form fields
     * @param array<string, UploadedFile|list<UploadedFile>> $files    Uploaded files keyed by field name
     */
    protected function executeApiMultipartWriteRequestAs(
        string $method,
        string $path,
        int $feUserId,
        array $fields = [],
        array $files = [],
    ): ResponseInterface {
        $uri      = 'http://localhost' . $path;
        $boundary = 'testboundary' . uniqid();

        $request = (new InternalRequest($uri))
            ->withMethod($method)
            ->withAddedHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
            ->withParsedBody($fields)
            ->withUploadedFiles($files);

        return $this->executeFrontendSubRequest(
            $request,
            (new InternalRequestContext())->withFrontendUserId($feUserId),
        );
    }

    /**
     * Execute a multipart/form-data write request built as a *raw body stream*,
     * the way a real PUT/PATCH request arrives over the wire.
     *
     * Unlike executeApiMultipartWriteRequestAs(), this does NOT inject parsed body
     * or uploaded files via the PSR-7 setters. PHP's SAPI does not populate those
     * for non-POST multipart requests, so this helper reproduces issue #143 and
     * verifies that the middleware parses the raw body itself.
     *
     * @param array<string, string>                       $fields Scalar form fields
     * @param array<int, array{name: string, filename: string, mimeType: string, content: string}> $files
     */
    protected function executeApiRawMultipartWriteRequestAs(
        string $method,
        string $path,
        int $feUserId,
        array $fields = [],
        array $files = [],
    ): ResponseInterface {
        $uri      = 'http://localhost' . $path;
        $boundary = '----testboundary' . uniqid();
        $crlf     = "\r\n";

        $parts = '';
        foreach ($fields as $name => $value) {
            $parts .= '--' . $boundary . $crlf;
            $parts .= 'Content-Disposition: form-data; name="' . $name . '"' . $crlf . $crlf;
            $parts .= $value . $crlf;
        }
        foreach ($files as $file) {
            $parts .= '--' . $boundary . $crlf;
            $parts .= 'Content-Disposition: form-data; name="' . $file['name'] . '"; filename="' . $file['filename'] . '"' . $crlf;
            $parts .= 'Content-Type: ' . $file['mimeType'] . $crlf . $crlf;
            $parts .= $file['content'] . $crlf;
        }
        $parts .= '--' . $boundary . '--' . $crlf;

        $body = new Stream('php://temp', 'rw');
        $body->write($parts);
        $body->rewind();

        $request = (new InternalRequest($uri))
            ->withMethod($method)
            ->withAddedHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
            ->withBody($body);

        return $this->executeFrontendSubRequest(
            $request,
            (new InternalRequestContext())->withFrontendUserId($feUserId),
        );
    }

    /**
     * Create a PSR-7 UploadedFile from raw content for use in test multipart requests.
     */
    protected function createUploadedFile(
        string $content,
        string $filename,
        string $mimeType,
        int $error = \UPLOAD_ERR_OK,
    ): UploadedFile {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($content);
        $stream->rewind();

        return new UploadedFile(
            $stream,
            \strlen($content),
            $error,
            $filename,
            $mimeType,
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
