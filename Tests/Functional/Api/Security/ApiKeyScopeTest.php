<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Security;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * `AccessRole::API_KEY` and scopes, with principals from the `test_authenticator`
 * fixture (`X-Test-Auth`, `X-Test-Scopes`).
 */
final class ApiKeyScopeTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../../Fixtures/Extensions/test_authenticator',
    ];

    private const CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'key-arts',
            'resourceType' => 'Article',
            'operations'   => ['list', 'show', 'create', 'update'],
            'storagePid' => 1,
        ],
        'columns' => [
            'title' => [
                'groups'   => ['list', 'show', 'create', 'update'],
                'required' => true,
            ],
        ],
        'order' => [
            'allowed' => ['uid'],
            'default' => ['uid' => 'asc'],
        ],
        'security' => [
            'list'   => AccessRole::PUBLIC,
            'show'   => AccessRole::API_KEY,
            'create' => [AccessRole::API_KEY, ['key-arts:write']],
            'update' => AccessRole::FE_USER,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/articles_owned.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->registerResource('key-arts', self::CONFIG);
    }

    public function testApiKeyRoleAcceptsAnyCredentialButNoSession(): void
    {
        self::assertSame(200, $this->request('GET', '/_api/key-arts/90', 'service:ci')->getStatusCode());
        self::assertSame(200, $this->request('GET', '/_api/key-arts/90', 'fe:1')->getStatusCode());
        self::assertSame(403, $this->executeApiRequestAsBackendUser('/_api/key-arts/90', 2)->getStatusCode());
        self::assertSame(403, $this->executeApiRequest('/_api/key-arts/90')->getStatusCode());
    }

    public function testRequiredScopesMustBeGranted(): void
    {
        self::assertSame(201, $this->request('POST', '/_api/key-arts', 'service:ci', 'key-arts:write', ['title' => 'A'])->getStatusCode());
        self::assertSame(201, $this->request('POST', '/_api/key-arts', 'service:ci', '*:write', ['title' => 'B'])->getStatusCode());
        self::assertSame(403, $this->request('POST', '/_api/key-arts', 'service:ci', 'key-arts:read', ['title' => 'C'])->getStatusCode());
    }

    public function testUnrestrictedCredentialSatisfiesRequiredScopes(): void
    {
        self::assertSame(201, $this->request('POST', '/_api/key-arts', 'service:ci', null, ['title' => 'D'])->getStatusCode());
    }

    public function testScopesRestrictABoundUserBeyondItsRole(): void
    {
        self::assertSame(200, $this->request('PUT', '/_api/key-arts/90', 'fe:1', 'key-arts:write', ['title' => 'In scope'])->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/_api/key-arts/90', 'fe:1', 'key-arts:read', ['title' => 'Out of scope'])->getStatusCode());
    }

    public function testScopesRestrictPublicOperations(): void
    {
        self::assertSame(200, $this->request('GET', '/_api/key-arts', 'service:ci', 'key-arts:read')->getStatusCode());
        self::assertSame(403, $this->request('GET', '/_api/key-arts', 'service:ci', 'colors:read')->getStatusCode());
    }

    public function testInvalidScopeIsRejectedAtBoot(): void
    {
        $config = self::CONFIG;
        $config['security']['create'] = [AccessRole::API_KEY, ['key-arts:delete']];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('security["create"] requires a list of scopes');
        ApiDefinition::fromArray($config);
    }

    private function request(string $method, string $path, string $auth, ?string $scopes = null, array $data = []): ResponseInterface
    {
        $request = (new InternalRequest($this->apiHost . $path))
            ->withMethod($method)
            ->withAddedHeader('X-Test-Auth', $auth);
        if ($scopes !== null) {
            $request = $request->withAddedHeader('X-Test-Scopes', $scopes);
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
