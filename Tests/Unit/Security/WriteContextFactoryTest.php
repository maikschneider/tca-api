<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Security;

use MaikSchneider\TcaApi\Enum\WriteMode;
use MaikSchneider\TcaApi\Security\Authentication\Principal;
use MaikSchneider\TcaApi\Security\WriteContextFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class WriteContextFactoryTest extends TestCase
{
    private WriteContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new WriteContextFactory();
        unset($GLOBALS['BE_USER']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
    }

    private function requestWithFeUser(int $uid, string $username): ServerRequestInterface
    {
        $feUser = new \stdClass();
        $feUser->user = ['uid' => $uid, 'username' => $username];

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(static fn (string $name): ?object => $name === 'frontend.user' ? $feUser : null);

        return $request;
    }

    private function requestWithNoUser(): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturn(null);

        return $request;
    }

    private function requestWithPrincipal(Principal $principal): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(static fn (string $name): ?object => $name === Principal::REQUEST_ATTRIBUTE ? $principal : null);

        return $request;
    }

    // ── Frontend user ────────────────────────────────────────────────────

    #[Test]
    public function fromRequestReturnsFrontendUserContextWhenFeUserAttributeIsSet(): void
    {
        $request = $this->requestWithFeUser(7, 'johndoe');
        $context = $this->factory->fromRequest($request);

        self::assertSame('fe_user', $context->actorType);
        self::assertSame(7, $context->actorUid);
        self::assertSame('johndoe', $context->actorUsername);
        self::assertSame(WriteMode::ACTING_USER, $context->mode);
    }

    #[Test]
    public function fromRequestPassesWriteModeToFrontendUserContext(): void
    {
        $request = $this->requestWithFeUser(7, 'johndoe');
        $context = $this->factory->fromRequest($request, WriteMode::SYSTEM_ADMIN);

        self::assertSame(WriteMode::SYSTEM_ADMIN, $context->mode);
        self::assertSame('fe_user', $context->actorType);
    }

    // ── Backend user ─────────────────────────────────────────────────────

    #[Test]
    public function fromRequestReturnsBackendUserContextWhenBeUserGlobalIsSet(): void
    {
        $beUser = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->getMock();
        $beUser->user = ['uid' => 1, 'username' => 'admin'];
        $GLOBALS['BE_USER'] = $beUser;

        $context = $this->factory->fromRequest($this->requestWithNoUser());

        self::assertSame('be_user', $context->actorType);
        self::assertSame(1, $context->actorUid);
        self::assertSame('admin', $context->actorUsername);
    }

    // ── System fallback ──────────────────────────────────────────────────

    #[Test]
    public function fromRequestReturnsSystemContextWhenNoUserPresent(): void
    {
        $context = $this->factory->fromRequest($this->requestWithNoUser());

        self::assertSame('system', $context->actorType);
        self::assertSame(0, $context->actorUid);
        self::assertSame(WriteMode::SYSTEM_ADMIN, $context->mode);
    }

    #[Test]
    public function fromRequestReturnsSystemContextWhenBeUserHasNoUid(): void
    {
        $beUser = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->getMock();
        $beUser->user = [];
        $GLOBALS['BE_USER'] = $beUser;

        $context = $this->factory->fromRequest($this->requestWithNoUser());

        self::assertSame('system', $context->actorType);
    }

    // ── Principal from an authenticator ──────────────────────────────────

    #[Test]
    public function fromRequestReturnsServiceContextForAServicePrincipal(): void
    {
        $principal = new Principal(isService: true, serviceLabel: 'CI deploy', serviceUid: 7, source: 'api_key', credential: 'api_key:7:t3key_AbCdEfGh');

        $context = $this->factory->fromRequest($this->requestWithPrincipal($principal));

        self::assertSame('api_key', $context->actorType);
        self::assertSame(7, $context->actorUid);
        self::assertSame('CI deploy', $context->actorUsername);
        self::assertSame('api_key:7:t3key_AbCdEfGh', $context->credential);
        self::assertSame(WriteMode::ACTING_USER, $context->mode);
    }

    #[Test]
    public function fromRequestKeepsTheBoundUserAsActorAndRecordsTheCredential(): void
    {
        $principal = new Principal(frontendUserUid: 4, frontendUsername: 'jane', source: 'api_key', credential: 'api_key:9:t3key_AbCdEfGh');

        $context = $this->factory->fromRequest($this->requestWithPrincipal($principal));

        self::assertSame('fe_user', $context->actorType);
        self::assertSame(4, $context->actorUid);
        self::assertSame('api_key:9:t3key_AbCdEfGh', $context->credential);
    }

    #[Test]
    public function fromRequestNamesAServiceWithoutSourceAService(): void
    {
        $principal = new Principal(isService: true, serviceLabel: 'cron', serviceUid: 1);

        self::assertSame('service', $this->factory->fromRequest($this->requestWithPrincipal($principal))->actorType);
    }

    #[Test]
    public function fromRequestNamesAnUnlabelledServiceAfterItsSource(): void
    {
        $principal = new Principal(isService: true, source: 'api_key');

        self::assertSame('api_key', $this->factory->fromRequest($this->requestWithPrincipal($principal))->actorUsername);
    }

    #[Test]
    public function fromRequestPrefersTheFrontendUserWhenThePrincipalCarriesBoth(): void
    {
        $principal = new Principal(frontendUserUid: 4, frontendUsername: 'jane', backendUserUid: 2, backendUsername: 'admin', isBackendAdmin: true);

        $context = $this->factory->fromRequest($this->requestWithPrincipal($principal));

        self::assertSame('fe_user', $context->actorType);
        self::assertSame(4, $context->actorUid);
    }
}
