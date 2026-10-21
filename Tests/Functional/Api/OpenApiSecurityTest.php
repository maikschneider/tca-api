<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Api;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Tests\Functional\ApiFunctionalTestCase;

/**
 * `components.securitySchemes` and per-operation `security`. The
 * `test_authenticator` fixture registers the credential scheme `TestAuth`.
 */
final class OpenApiSecurityTest extends ApiFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'tca_api',
        __DIR__ . '/../Fixtures/Extensions/test_authenticator',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
    }

    public function testSessionAndRegisteredSchemesAreDeclared(): void
    {
        $schemes = $this->spec()['components']['securitySchemes'];

        self::assertSame(['type' => 'apiKey', 'in' => 'cookie', 'name' => 'fe_typo_user', 'description' => 'TYPO3 frontend user session.'], $schemes['FrontendSession']);
        self::assertSame('be_typo_user', $schemes['BackendSession']['name']);
        self::assertSame(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Test-Auth'], $schemes['TestAuth']);
    }

    public function testRolesMapToTheSchemesThatCanSatisfyThem(): void
    {
        $paths = $this->spec()['paths'];

        self::assertSame([], $paths['/_api/articles']['get']['security']);
        self::assertSame(
            [['FrontendSession' => []], ['TestAuth' => ['articles:write']]],
            $paths['/_api/articles']['post']['security'],
        );
        self::assertSame(
            [['BackendSession' => []], ['TestAuth' => ['articles:write']]],
            $paths['/_api/articles/{uid}']['delete']['security'],
        );
    }

    public function testProtectedOperationsDocument401AndPublicOnesDoNot(): void
    {
        $paths = $this->spec()['paths'];

        self::assertArrayHasKey('401', $paths['/_api/articles']['post']['responses']);
        self::assertArrayNotHasKey('401', $paths['/_api/articles']['get']['responses']);
    }

    public function testApiKeyRoleListsOnlyCredentialSchemesWithItsScopes(): void
    {
        $this->registerResource('key-arts', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'key-arts',
                'resourceType' => 'KeyArticle',
                'operations'   => ['list', 'create'],
                'storagePid' => 1,
            ],
            'security' => [
                'list'   => AccessRole::API_KEY,
                'create' => [AccessRole::API_KEY, ['key-arts:write', 'colors:read']],
            ],
        ]);

        $paths = $this->spec()['paths'];

        self::assertSame([['TestAuth' => ['key-arts:read']]], $paths['/_api/key-arts']['get']['security']);
        self::assertSame([['TestAuth' => ['key-arts:write', 'colors:read']]], $paths['/_api/key-arts']['post']['security']);
    }

    public function testCallableVoterAllowsEverySchemeAndAnonymous(): void
    {
        $this->registerResource('voter-arts', [
            'general' => [
                'table'        => 'tx_myext_domain_model_article',
                'resourceName' => 'voter-arts',
                'resourceType' => 'VoterArticle',
                'operations'   => ['list'],
                'storagePid' => 1,
            ],
            'security' => ['list' => [self::class, 'vote']],
        ]);

        $spec = json_decode((string)$this->executeApiRequest('/_api/openapi.json')->getBody(), false, 512, JSON_THROW_ON_ERROR);
        $security = $spec->paths->{'/_api/voter-arts'}->get->security;

        self::assertCount(4, $security);
        self::assertEquals(new \stdClass(), $security[3]);
    }

    public static function vote(): bool
    {
        return true;
    }

    private function spec(): array
    {
        return $this->decodeResponseBody($this->executeApiRequest('/_api/openapi.json'));
    }
}
