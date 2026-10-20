<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api\Security;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Principals resolved by a custom authenticator (the `test_authenticator`
 * fixture, reading `X-Test-Auth`) satisfy the same access roles as sessions.
 */
final class AuthenticatorChainTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../../Fixtures/Extensions/test_authenticator',
    ];

    private const CONFIG = [
        'general' => [
            'table'        => 'tx_myext_domain_model_article',
            'resourceName' => 'auth-arts',
            'resourceType' => 'Article',
            'operations'   => ['show', 'create', 'update', 'delete'],
            'storagePid' => 1,
        ],
        'columns' => [
            'title' => [
                'groups'   => ['show', 'create', 'update'],
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->registerResource('auth-arts', self::CONFIG);
    }

    public function testFrontendPrincipalCreatesAndOwnsTheRecord(): void
    {
        $response = $this->writeWithAuth('POST', '/_api/auth-arts', 'fe:1', ['title' => 'Via authenticator']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->decodeResponseBody($response)['fe_user_id']);
    }

    public function testFrontendPrincipalIsCheckedAgainstTheOwner(): void
    {
        self::assertSame(200, $this->writeWithAuth('PUT', '/_api/auth-arts/90', 'fe:1', ['title' => 'Owner'])->getStatusCode());
        self::assertSame(403, $this->writeWithAuth('PUT', '/_api/auth-arts/90', 'fe:2', ['title' => 'Not owner'])->getStatusCode());
    }

    public function testBackendPrincipalSatisfiesBackendRoles(): void
    {
        self::assertSame(200, $this->executeApiRequestWithHeaders('/_api/auth-arts/90', ['X-Test-Auth' => 'be:3'])->getStatusCode());
        self::assertSame(403, $this->writeWithAuth('DELETE', '/_api/auth-arts/90', 'be:3')->getStatusCode());
        self::assertSame(204, $this->writeWithAuth('DELETE', '/_api/auth-arts/90', 'be:2:admin')->getStatusCode());
    }

    public function testServicePrincipalHasNoUserRole(): void
    {
        self::assertSame(403, $this->writeWithAuth('POST', '/_api/auth-arts', 'service:ci', ['title' => 'Service'])->getStatusCode());
        self::assertSame(403, $this->executeApiRequestWithHeaders('/_api/auth-arts/90', ['X-Test-Auth' => 'service:ci'])->getStatusCode());
    }

    public function testFailedAuthenticationAnswers401WithChallenge(): void
    {
        $response = $this->executeApiRequestWithHeaders('/_api/auth-arts/90', ['X-Test-Auth' => 'bogus']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Test realm="tca_api"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('Unknown test credentials', $this->decodeResponseBody($response)['hydra:description'] ?? null);
    }

    public function testFailedAuthenticationIsNotOverriddenByASession(): void
    {
        $response = $this->executeFrontendSubRequest(
            (new InternalRequest($this->apiHost . '/_api/auth-arts/90'))->withAddedHeader('X-Test-Auth', 'bogus'),
            (new \TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext())->withBackendUserId(2),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testRequestWithoutCredentialsFallsThroughToTheSession(): void
    {
        self::assertSame(200, $this->executeApiRequestAsBackendUser('/_api/auth-arts/90', 3)->getStatusCode());
        self::assertSame(403, $this->executeApiRequest('/_api/auth-arts/90')->getStatusCode());
    }

    private function writeWithAuth(string $method, string $path, string $auth, array $data = []): ResponseInterface
    {
        $body = new Stream('php://temp', 'rw');
        if ($data !== []) {
            $body->write(json_encode($data, JSON_THROW_ON_ERROR));
            $body->rewind();
        }

        return $this->executeFrontendSubRequest(
            (new InternalRequest($this->apiHost . $path))
                ->withMethod($method)
                ->withAddedHeader('Content-Type', 'application/json')
                ->withAddedHeader('X-Test-Auth', $auth)
                ->withBody($body),
        );
    }
}
