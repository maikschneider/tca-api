<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Security\Authentication;

use MaikSchneider\TcaApi\Security\Authentication\Principal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class PrincipalTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    public static function authenticationProvider(): \Generator
    {
        yield 'anonymous' => [Principal::anonymous(), false];
        yield 'frontend user' => [new Principal(frontendUserUid: 4), true];
        yield 'backend user' => [new Principal(backendUserUid: 2), true];
        yield 'service' => [new Principal(isService: true, source: 'api_key'), true];
        yield 'frontend username without uid' => [new Principal(frontendUsername: 'jane'), false];
        yield 'admin flag without backend uid' => [new Principal(isBackendAdmin: true), false];
    }

    #[Test]
    #[DataProvider('authenticationProvider')]
    public function isAuthenticatedRequiresAUserUidOrAService(Principal $principal, bool $expected): void
    {
        self::assertSame($expected, $principal->isAuthenticated());
    }

    #[Test]
    public function isBackendAdminRequiresABackendUser(): void
    {
        self::assertTrue((new Principal(backendUserUid: 2, isBackendAdmin: true))->isBackendAdmin());
        self::assertFalse((new Principal(backendUserUid: 2))->isBackendAdmin());
        self::assertFalse((new Principal(isBackendAdmin: true))->isBackendAdmin());
    }

    #[Test]
    public function fromRequestReturnsTheResolvedPrincipal(): void
    {
        $principal = new Principal(isService: true, source: 'api_key');

        self::assertSame($principal, Principal::fromRequest($this->request([Principal::REQUEST_ATTRIBUTE => $principal])));
    }

    #[Test]
    public function fromRequestFallsBackToTheSessionAndThenToAnonymous(): void
    {
        $frontendUser = new \stdClass();
        $frontendUser->user = ['uid' => 4, 'username' => 'jane', 'usergroup' => '1,3'];

        $fromSession = Principal::fromRequest($this->request(['frontend.user' => $frontendUser]));
        self::assertSame(4, $fromSession->frontendUserUid);
        self::assertSame([1, 3], $fromSession->frontendGroupIds);
        self::assertSame('session', $fromSession->source);

        self::assertFalse(Principal::fromRequest($this->request([]))->isAuthenticated());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function request(array $attributes): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(static fn (string $name): mixed => $attributes[$name] ?? null);

        return $request;
    }
}
