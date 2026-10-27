<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\OpenApi\OpenApiOperationBuilder;
use MaikSchneider\TcaApi\OpenApi\OpenApiPathsBuilder;
use MaikSchneider\TcaApi\OpenApi\OpenApiSecurityBuilder;
use MaikSchneider\TcaApi\OpenApi\SecurityScheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

final class OpenApiPathsBuilderTest extends TestCase
{
    #[Test]
    public function protectedOperationNoSchemeCanSatisfyDocuments401WithoutRequirement(): void
    {
        $operation = $this->buildPaths(['list' => AccessRole::BE_USER])['/_api/tests']['get'];

        self::assertArrayNotHasKey('security', $operation);
        self::assertArrayHasKey('401', $operation['responses']);
    }

    #[Test]
    public function protectedOperationDocuments401WhenEverySchemeWasRemoved(): void
    {
        $operation = $this->buildPaths(['list' => AccessRole::FE_USER], schemes: [])['/_api/tests']['get'];

        self::assertArrayNotHasKey('security', $operation);
        self::assertArrayHasKey('401', $operation['responses']);
    }

    #[Test]
    public function publicAndDisabledOperationsDocumentNo401(): void
    {
        $paths = $this->buildPaths(['list' => AccessRole::PUBLIC, 'show' => AccessRole::DISABLED]);

        self::assertSame([], $paths['/_api/tests']['get']['security']);
        self::assertArrayNotHasKey('401', $paths['/_api/tests']['get']['responses']);
        self::assertArrayNotHasKey('security', $paths['/_api/tests/{uid}']['get']);
        self::assertArrayNotHasKey('401', $paths['/_api/tests/{uid}']['get']['responses']);
    }

    /**
     * @param array<string, AccessRole> $security
     * @param array<string, SecurityScheme>|null $schemes
     */
    private function buildPaths(array $security, ?array $schemes = null): array
    {
        $config = ApiDefinition::fromArray([
            'general' => [
                'table'        => 'tx_test',
                'resourceName' => 'tests',
                'resourceType' => 'Test',
            ],
            'security' => $security,
        ]);
        $builder = new OpenApiPathsBuilder(
            new OpenApiOperationBuilder(),
            new OpenApiSecurityBuilder(self::createStub(EventDispatcherInterface::class)),
        );
        $schemes ??= ['FrontendSession' => new SecurityScheme('FrontendSession', [], authenticatesFrontendUsers: true)];

        return $builder->build(['tests' => $config], new RequestContext(new SiteSettings(new Settings([]), [], []), new ServerRequest('https://example.org/')), $schemes);
    }
}
