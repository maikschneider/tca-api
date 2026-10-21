<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\OpenApi;

use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\OpenApi\OpenApiSecurityBuilder;
use MaikSchneider\TcaApi\OpenApi\SecurityScheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class OpenApiSecurityBuilderTest extends TestCase
{
    #[Test]
    public function ownerAcceptsBackendSchemesThroughTheOwnershipBypass(): void
    {
        self::assertSame(
            [['FrontendSession' => []], ['BackendSession' => []]],
            $this->builder()->buildRequirement(AccessRole::OWNER, 'articles', 'update', $this->schemes()),
        );
    }

    #[Test]
    public function ownerWithoutBypassAcceptsFrontendSchemesOnly(): void
    {
        self::assertSame(
            [['FrontendSession' => []]],
            $this->builder()->buildRequirement(AccessRole::OWNER, 'articles', 'update', $this->schemes(), ownershipBeAdminBypass: false),
        );
    }

    private function builder(): OpenApiSecurityBuilder
    {
        return new OpenApiSecurityBuilder(self::createStub(EventDispatcherInterface::class));
    }

    /**
     * @return array<string, SecurityScheme>
     */
    private function schemes(): array
    {
        return [
            'FrontendSession' => new SecurityScheme('FrontendSession', [], authenticatesFrontendUsers: true),
            'BackendSession'  => new SecurityScheme('BackendSession', [], authenticatesBackendUsers: true),
        ];
    }
}
