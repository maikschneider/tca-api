<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\Loader\ApiDefinitionLoader;
use MaikSchneider\TcaApi\OpenApi\OpenApiBuilder;
use MaikSchneider\TcaApi\Registry\ApiRegistry;
use MaikSchneider\TcaApi\Tests\Functional\Contract\OpenApiResponseValidator;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;
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

    /** Scheme and host the request helpers send to; switch it to address another site. */
    protected string $apiHost = 'http://localhost';

    /**
     * Validate every API response against the OpenAPI spec of the site that served it.
     * Turn off only in tests that break the spec on purpose, e.g. with a broken resource config.
     */
    protected bool $validateResponsesAgainstOpenApi = true;

    /** Known mismatches per TYPO3 major version (%d), whose core TCA differs. */
    private const CONTRACT_BASELINE = __DIR__ . '/Contract/openapi-contract-baseline.v%d.txt';

    /** Set to collect mismatches into this file instead of failing; see Tests/Functional/Contract/README.md. */
    private const CONTRACT_COLLECT_ENV = 'TCA_API_CONTRACT_COLLECT';

    /** @var array<string, true>|null */
    private static ?array $contractBaseline = null;

    /**
     * Keyed by host and the object ids of the registered definitions; each entry holds
     * those definitions so their ids cannot be reused while the key exists.
     *
     * @var array<string, array{validator: OpenApiResponseValidator, resources: array<string, ApiDefinition>}>
     */
    private array $contractValidators = [];

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
     * Every request helper ends up here, so every response is checked against the spec.
     */
    protected function executeFrontendSubRequest(
        InternalRequest $request,
        ?InternalRequestContext $context = null,
        bool $followRedirects = false,
    ): ResponseInterface {
        $response = parent::executeFrontendSubRequest($request, $context, $followRedirects);

        if ($this->validateResponsesAgainstOpenApi) {
            $this->assertResponseMatchesOpenApi($request, $response);
        }

        return $response;
    }

    private function assertResponseMatchesOpenApi(InternalRequest $request, ResponseInterface $response): void
    {
        $contract = $this->contractValidator($request);
        if ($contract === null) {
            return;
        }
        [$validator, $languageBasePath] = $contract;

        // Spec paths start at the API prefix; a language base such as "/de" precedes it.
        $path = $request->getUri()->getPath();
        if ($languageBasePath !== '' && str_starts_with($path, $languageBasePath . '/')) {
            $path = substr($path, \strlen($languageBasePath));
        }

        $body = $response->getBody();
        $body->rewind();
        $mismatches = $validator->validate(
            $request->getMethod(),
            $path,
            $response->getStatusCode(),
            $response->getHeaderLine('Content-Type'),
            $body->getContents(),
        );
        $body->rewind();

        $collectFile = getenv(self::CONTRACT_COLLECT_ENV);
        if (\is_string($collectFile) && $collectFile !== '') {
            if ($mismatches !== []) {
                file_put_contents($collectFile, implode("\n", $mismatches) . "\n", FILE_APPEND | LOCK_EX);
            }
            return;
        }

        self::$contractBaseline ??= array_fill_keys(
            array_filter(
                array_map('trim', file(sprintf(self::CONTRACT_BASELINE, (new Typo3Version())->getMajorVersion())) ?: []),
                static fn (string $line): bool => $line !== '' && $line[0] !== '#',
            ),
            true,
        );
        $unexpected = array_values(array_filter($mismatches, static fn (string $mismatch): bool => !isset(self::$contractBaseline[$mismatch])));

        self::assertSame(
            [],
            $unexpected,
            'The response does not match the OpenAPI spec. Fix the spec or the response, or see Tests/Functional/Contract/README.md for the baseline.',
        );
    }

    /**
     * The spec is built in-process for the site that serves the request, so sites that
     * hide /openapi.json are covered too and validation never issues a request itself.
     *
     * @return array{OpenApiResponseValidator, string}|null the validator and the base path of the request's site language
     */
    private function contractValidator(InternalRequest $request): ?array
    {
        $serverRequest = new ServerRequest($request->getUri(), 'GET');
        $routeResult   = $this->get(SiteMatcher::class)->matchRequest($serverRequest);
        if (!$routeResult instanceof SiteRouteResult || !($site = $routeResult->getSite()) instanceof Site) {
            return null;
        }

        $resources = $this->getApiRegistry()->getAll();
        $key       = $site->getIdentifier() . '|' . $request->getUri()->getAuthority() . '|' . implode(',', array_map(
            static fn (ApiDefinition $definition): int => spl_object_id($definition),
            $resources,
        ));

        if (!isset($this->contractValidators[$key])) {
            $ctx = new RequestContext($site->getSettings(), $serverRequest->withAttribute('language', $routeResult->getLanguage()));
            $this->contractValidators[$key] = [
                'validator' => new OpenApiResponseValidator($this->get(OpenApiBuilder::class)->build($ctx)),
                'resources' => $resources,
            ];
        }

        return [$this->contractValidators[$key]['validator'], rtrim($routeResult->getLanguage()?->getBase()->getPath() ?? '', '/')];
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
        $uri = $this->apiHost . $path;
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
        $uri = $this->apiHost . $path;
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
        $uri = $this->apiHost . $path;
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
        $uri = $this->apiHost . $path;

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
        $uri = $this->apiHost . $path;

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
        $uri = $this->apiHost . $path;
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
        $uri = $this->apiHost . $path;

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
        $uri      = $this->apiHost . $path;
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
        $uri      = $this->apiHost . $path;
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
        $uri = $this->apiHost . $path;

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
