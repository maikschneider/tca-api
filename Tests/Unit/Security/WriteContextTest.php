<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Security;

use MaikSchneider\TcaApi\Enum\WriteMode;
use MaikSchneider\TcaApi\Security\WriteContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WriteContextTest extends TestCase
{
    #[Test]
    public function forFrontendUserCreatesCorrectContext(): void
    {
        $context = WriteContext::forFrontendUser(42, 'johndoe');

        self::assertSame(WriteMode::ACTING_USER, $context->mode);
        self::assertSame('fe_user', $context->actorType);
        self::assertSame(42, $context->actorUid);
        self::assertSame('johndoe', $context->actorUsername);
        self::assertFalse($context->isSystemMode());
    }

    #[Test]
    public function forBackendUserCreatesCorrectContext(): void
    {
        $context = WriteContext::forBackendUser(1, 'admin');

        self::assertSame(WriteMode::ACTING_USER, $context->mode);
        self::assertSame('be_user', $context->actorType);
        self::assertSame(1, $context->actorUid);
        self::assertSame('admin', $context->actorUsername);
        self::assertFalse($context->isSystemMode());
    }

    #[Test]
    public function forSystemCreatesSystemContext(): void
    {
        $context = WriteContext::forSystem();

        self::assertSame(WriteMode::SYSTEM_ADMIN, $context->mode);
        self::assertSame('system', $context->actorType);
        self::assertSame(0, $context->actorUid);
        self::assertSame('_tca_api_system', $context->actorUsername);
        self::assertTrue($context->isSystemMode());
    }

    #[Test]
    public function writeModeCanBeOverriddenForFrontendUser(): void
    {
        $context = WriteContext::forFrontendUser(42, 'johndoe', WriteMode::SYSTEM_ADMIN);

        self::assertSame(WriteMode::SYSTEM_ADMIN, $context->mode);
        self::assertSame('fe_user', $context->actorType);
        self::assertTrue($context->isSystemMode());
    }

    #[Test]
    public function writeModeCanBeOverriddenForBackendUser(): void
    {
        $context = WriteContext::forBackendUser(1, 'admin', WriteMode::SYSTEM_ADMIN);

        self::assertSame(WriteMode::SYSTEM_ADMIN, $context->mode);
        self::assertSame('be_user', $context->actorType);
        self::assertTrue($context->isSystemMode());
    }

    #[Test]
    public function contextWritesLiveByDefault(): void
    {
        self::assertSame(0, WriteContext::forFrontendUser(42, 'johndoe')->workspaceId);
    }

    #[Test]
    public function withWorkspaceKeepsTheActor(): void
    {
        $context = WriteContext::forFrontendUser(42, 'johndoe', WriteMode::ACTING_USER, 'key:7');
        $workspaceContext = $context->withWorkspace(3);

        self::assertSame(3, $workspaceContext->workspaceId);
        self::assertSame(0, $context->workspaceId);
        self::assertSame('fe_user', $workspaceContext->actorType);
        self::assertSame(42, $workspaceContext->actorUid);
        self::assertSame('johndoe', $workspaceContext->actorUsername);
        self::assertSame('key:7', $workspaceContext->credential);
        self::assertSame(WriteMode::ACTING_USER, $workspaceContext->mode);
    }
}
