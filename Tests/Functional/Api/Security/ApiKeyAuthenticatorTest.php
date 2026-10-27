<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Security;

use MaikSchneider\ApiKey\Authentication\ApiKeyAuthenticatorInterface;
use MaikSchneider\ApiKey\Key\ApiKeyCreator;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use PHPUnit\Framework\Attributes\RequiresMethod;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;

/**
 * Keys of the real `api_key` extension, mapped to principals by the bridge.
 */
#[RequiresMethod(ApiKeyAuthenticatorInterface::class, 'authenticate')]
final class ApiKeyAuthenticatorTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        'maikschneider/api-key',
    ];

    private const CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'keyed-arts',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show', 'create', 'update', 'delete'],
            'storagePid' => 1,
        ],
        'columns' => [
            'title' => [
                'groups'   => ['list', 'show', 'create', 'update'],
                'required' => true,
            ],
            'fe_user_id' => [
                'groups' => ['show'],
            ],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
        'security' => [
            'list'   => [AccessRole::API_KEY, ['keyed-arts:read']],
            'show'   => AccessRole::BE_USER,
            'create' => AccessRole::FE_USER,
            'update' => AccessRole::OWNER,
            'delete' => AccessRole::BE_ADMIN,
        ],
        'ownership' => [
            'column' => 'fe_user_id',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_owned.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->registerResource('keyed-arts', self::CONFIG);
    }

    public function testServiceKeyAuthenticatesWithXApiKeyHeader(): void
    {
        $key = $this->createKey('Importer');

        self::assertSame(200, $this->request('GET', '/_api/keyed-arts', ['X-API-Key' => $key])->getStatusCode());
        self::assertSame(403, $this->request('GET', '/_api/keyed-arts')->getStatusCode());
    }

    public function testServiceKeyAuthenticatesWithBearerToken(): void
    {
        $key = $this->createKey('Importer');

        self::assertSame(200, $this->request('GET', '/_api/keyed-arts', ['Authorization' => 'Bearer ' . $key])->getStatusCode());
    }

    public function testServiceKeyHasNoUserRole(): void
    {
        $key = $this->createKey('Importer');

        self::assertSame(403, $this->request('GET', '/_api/keyed-arts/90', ['X-API-Key' => $key])->getStatusCode());
        self::assertSame(403, $this->request('POST', '/_api/keyed-arts', ['X-API-Key' => $key], ['title' => 'Service'])->getStatusCode());
    }

    public function testFrontendUserBoundKeyActsAsThatUser(): void
    {
        $key = $this->createKey('Editor script', feUser: 1);

        $response = $this->request('POST', '/_api/keyed-arts', ['X-API-Key' => $key], ['title' => 'Via key']);
        self::assertSame(201, $response->getStatusCode());
        $uid = (int)$this->decodeResponseBody($response)['uid'];
        self::assertSame(1, $this->fetchOwner($uid));

        self::assertSame(200, $this->request('PUT', '/_api/keyed-arts/90', ['X-API-Key' => $key], ['title' => 'Owner'])->getStatusCode());
        $otherKey = $this->createKey('Other script', feUser: 2);
        self::assertSame(403, $this->request('PUT', '/_api/keyed-arts/90', ['X-API-Key' => $otherKey], ['title' => 'Not owner'])->getStatusCode());
    }

    public function testBackendUserBoundKeyHonoursTheAdminFlag(): void
    {
        $editorKey = $this->createKey('Editor', beUser: 3);
        $adminKey = $this->createKey('Admin', beUser: 2);

        self::assertSame(200, $this->request('GET', '/_api/keyed-arts/90', ['X-API-Key' => $editorKey])->getStatusCode());
        self::assertSame(403, $this->request('DELETE', '/_api/keyed-arts/90', ['X-API-Key' => $editorKey])->getStatusCode());
        self::assertSame(204, $this->request('DELETE', '/_api/keyed-arts/90', ['X-API-Key' => $adminKey])->getStatusCode());
    }

    public function testScopesRestrictABoundKeyBelowItsUser(): void
    {
        $key = $this->createKey('Read only', feUser: 1, scopes: ['keyed-arts:read']);

        self::assertSame(403, $this->request('PUT', '/_api/keyed-arts/90', ['X-API-Key' => $key], ['title' => 'Owner'])->getStatusCode());
        self::assertSame(200, $this->request('GET', '/_api/keyed-arts', ['X-API-Key' => $key])->getStatusCode());
    }

    public function testServiceKeyWithoutTheRequiredScopeIsDenied(): void
    {
        $key = $this->createKey('Other resource', scopes: ['colors:read']);

        self::assertSame(403, $this->request('GET', '/_api/keyed-arts', ['X-API-Key' => $key])->getStatusCode());
    }

    public function testInvalidKeyAnswers401EvenWithASession(): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($this->apiHost . '/_api/keyed-arts/90'))->withAddedHeader('X-API-Key', 't3key_invalidinvalidinvalidinvalid'),
            (new InternalRequestContext())->withBackendUserId(2),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Bearer realm="tca_api"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertStringContainsString('ApiKey realm="tca_api"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testRevokedKeyAnswers401(): void
    {
        $key = $this->createKey('Revoked');
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_apikey_key')->update('tx_apikey_key', ['hidden' => 1], ['title' => 'Revoked']);

        self::assertSame(401, $this->request('GET', '/_api/keyed-arts', ['X-API-Key' => $key])->getStatusCode());
    }

    public function testForeignBearerTokenIsLeftToOtherAuthenticators(): void
    {
        self::assertSame(403, $this->request('GET', '/_api/keyed-arts', ['Authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.e30.x'])->getStatusCode());
    }

    public function testKeyedRequestsCreateNoSession(): void
    {
        $key = $this->createKey('Editor script', feUser: 1);

        $response = $this->request('PUT', '/_api/keyed-arts/90', ['X-API-Key' => $key], ['title' => 'Stateless']);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame(0, $this->get(ConnectionPool::class)->getConnectionForTable('fe_sessions')->count('*', 'fe_sessions', []));
        self::assertSame(0, (int)$this->get(ConnectionPool::class)->getConnectionForTable('fe_users')->select(['lastlogin'], 'fe_users', ['uid' => 1])->fetchOne());
    }

    public function testOpenApiDocumentsBothKeySchemes(): void
    {
        $spec = $this->decodeResponseBody($this->request('GET', '/_api/openapi.json'));

        self::assertSame(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key', 'description' => 'API key of the api_key extension.'], $spec['components']['securitySchemes']['ApiKey']);
        self::assertSame('bearer', $spec['components']['securitySchemes']['ApiKeyBearer']['scheme']);
        self::assertContains(['ApiKey' => ['keyed-arts:write']], $spec['paths']['/_api/keyed-arts']['post']['security']);
    }

    /**
     * @param list<string> $scopes
     */
    private function createKey(string $title, int $feUser = 0, int $beUser = 0, array $scopes = []): string
    {
        return $this->get(ApiKeyCreator::class)->create($title, feUser: $feUser, beUser: $beUser, scopes: $scopes)->secret;
    }

    private function fetchOwner(int $uid): int
    {
        return (int)$this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_myext_domain_model_article')
            ->select(['fe_user_id'], 'tx_myext_domain_model_article', ['uid' => $uid])
            ->fetchOne();
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array $headers = [], array $data = []): ResponseInterface
    {
        $request = (new InternalRequest($this->apiHost . $path))->withMethod($method);
        foreach ($headers as $name => $value) {
            $request = $request->withAddedHeader($name, $value);
        }
        if ($data !== []) {
            $body = new Stream('php://temp', 'rw');
            $body->write(json_encode($data, JSON_THROW_ON_ERROR));
            $body->rewind();
            $request = $request->withAddedHeader('Content-Type', 'application/json')->withBody($body);
        }

        return $this->executeFrontendSubRequest($request);
    }
}
