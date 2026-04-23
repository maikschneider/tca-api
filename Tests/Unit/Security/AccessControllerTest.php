<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Unit\Security;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Security\AccessController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class AccessControllerTest extends TestCase
{
    private AccessController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new AccessController();
        unset($GLOBALS['BE_USER']);
    }

    // ── Helper factories ──────────────────────────────────────────────────────

    /**
     * Build a request mock that returns $feUser for the 'frontend.user' attribute.
     */
    private function makeRequest(?object $feUser = null): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('frontend.user')
            ->willReturn($feUser);

        return $request;
    }

    /**
     * Build a minimal frontend-user stub with the given UID and group CSV.
     */
    private function makeFrontendUser(int $uid, string $groups = ''): object
    {
        $feUser = new \stdClass();
        $feUser->user = ['uid' => $uid, 'usergroup' => $groups];

        return $feUser;
    }

    // ── AccessRole::PUBLIC ────────────────────────────────────────────────────

    #[Test]
    public function publicRoleAlwaysGrantsAccess(): void
    {
        $request = $this->makeRequest();

        self::assertTrue($this->controller->isAllowed(AccessRole::PUBLIC, $request));
    }

    #[Test]
    public function publicRoleGrantsAccessEvenWithoutFrontendUser(): void
    {
        $request = $this->makeRequest(null);

        self::assertTrue($this->controller->isAllowed(AccessRole::PUBLIC, $request));
    }

    // ── AccessRole::DISABLED ─────────────────────────────────────────────────

    #[Test]
    public function disabledRoleAlwaysDeniesAccess(): void
    {
        $request = $this->makeRequest();

        self::assertFalse($this->controller->isAllowed(AccessRole::DISABLED, $request));
    }

    #[Test]
    public function disabledRoleDeniesEvenWithAuthenticatedFrontendUser(): void
    {
        $request = $this->makeRequest($this->makeFrontendUser(1));

        self::assertFalse($this->controller->isAllowed(AccessRole::DISABLED, $request));
    }

    #[Test]
    public function disabledRoleDeniesEvenWithBackendAdmin(): void
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->user = ['uid' => 1];
        $beUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->makeRequest();

        self::assertFalse($this->controller->isAllowed(AccessRole::DISABLED, $request));
    }

    // ── AccessRole::FE_USER ───────────────────────────────────────────────────

    #[Test]
    public function feUserRoleDeniesWhenNoFrontendUser(): void
    {
        $request = $this->makeRequest(null);

        self::assertFalse($this->controller->isAllowed(AccessRole::FE_USER, $request));
    }

    #[Test]
    public function feUserRoleDeniesWhenFrontendUserHasNoUid(): void
    {
        $feUser = new \stdClass();
        $feUser->user = ['uid' => 0];
        $request = $this->makeRequest($feUser);

        self::assertFalse($this->controller->isAllowed(AccessRole::FE_USER, $request));
    }

    #[Test]
    public function feUserRoleGrantsWhenFrontendUserPresent(): void
    {
        $request = $this->makeRequest($this->makeFrontendUser(42));

        self::assertTrue($this->controller->isAllowed(AccessRole::FE_USER, $request));
    }

    // ── AccessRole::FE_GROUP ──────────────────────────────────────────────────

    #[Test]
    public function feGroupRoleDeniesWhenNoFrontendUser(): void
    {
        $request = $this->makeRequest(null);

        self::assertFalse($this->controller->isAllowed(AccessRole::FE_GROUP, $request));
    }

    #[Test]
    public function feGroupRoleGrantsWhenUserBelongsToAnyGroup(): void
    {
        $request = $this->makeRequest($this->makeFrontendUser(1, '3,7'));

        self::assertTrue($this->controller->isAllowed(AccessRole::FE_GROUP, $request));
    }

    #[Test]
    public function feGroupRoleDeniesWhenUserBelongsToNoGroup(): void
    {
        $request = $this->makeRequest($this->makeFrontendUser(1, ''));

        self::assertFalse($this->controller->isAllowed(AccessRole::FE_GROUP, $request));
    }

    #[Test]
    public function feGroupRoleWithSpecificGroupGrantsWhenUserInGroup(): void
    {
        $request = $this->makeRequest($this->makeFrontendUser(1, '3,7'));

        self::assertTrue($this->controller->isAllowed([AccessRole::FE_GROUP, [3]], $request));
    }

    #[Test]
    public function feGroupRoleWithSpecificGroupDeniesWhenUserNotInGroup(): void
    {
        $request = $this->makeRequest($this->makeFrontendUser(1, '5'));

        self::assertFalse($this->controller->isAllowed([AccessRole::FE_GROUP, [3]], $request));
    }

    #[Test]
    public function feGroupRoleWithEmptyGroupListGrantsAnyGroupMember(): void
    {
        $request = $this->makeRequest($this->makeFrontendUser(1, '99'));

        self::assertTrue($this->controller->isAllowed([AccessRole::FE_GROUP, []], $request));
    }

    // ── AccessRole::BE_USER ───────────────────────────────────────────────────

    #[Test]
    public function beUserRoleDeniesWhenNoBackendUser(): void
    {
        $request = $this->makeRequest();

        self::assertFalse($this->controller->isAllowed(AccessRole::BE_USER, $request));
    }

    #[Test]
    public function beUserRoleGrantsWhenBackendUserPresent(): void
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->user = ['uid' => 1];
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->makeRequest();

        self::assertTrue($this->controller->isAllowed(AccessRole::BE_USER, $request));
    }

    #[Test]
    public function beUserRoleDeniesWhenBackendUserHasNoUid(): void
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->user = ['uid' => 0];
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->makeRequest();

        self::assertFalse($this->controller->isAllowed(AccessRole::BE_USER, $request));
    }

    // ── AccessRole::BE_ADMIN ──────────────────────────────────────────────────

    #[Test]
    public function beAdminRoleDeniesWhenNoBackendUser(): void
    {
        $request = $this->makeRequest();

        self::assertFalse($this->controller->isAllowed(AccessRole::BE_ADMIN, $request));
    }

    #[Test]
    public function beAdminRoleDeniesWhenBackendUserIsNotAdmin(): void
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->user = ['uid' => 1];
        $beUser->method('isAdmin')->willReturn(false);
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->makeRequest();

        self::assertFalse($this->controller->isAllowed(AccessRole::BE_ADMIN, $request));
    }

    #[Test]
    public function beAdminRoleGrantsWhenBackendAdminPresent(): void
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->user = ['uid' => 1];
        $beUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->makeRequest();

        self::assertTrue($this->controller->isAllowed(AccessRole::BE_ADMIN, $request));
    }

    // ── AccessRole::OWNER ─────────────────────────────────────────────────────

    #[Test]
    public function ownerRoleDeniesWhenNoOwnershipConfigured(): void
    {
        $request = $this->makeRequest($this->makeFrontendUser(1));
        $config  = $this->buildDefinitionWithoutOwnership();

        self::assertFalse($this->controller->isAllowed(AccessRole::OWNER, $request, ['fe_user_id' => 1], $config));
    }

    #[Test]
    public function ownerRoleDeniesWhenNoFrontendUser(): void
    {
        $request = $this->makeRequest(null);
        $config  = $this->buildDefinitionWithOwnership('fe_user_id');

        self::assertFalse($this->controller->isAllowed(AccessRole::OWNER, $request, ['fe_user_id' => 1], $config));
    }

    #[Test]
    public function ownerRoleGrantsWhenUserMatchesRecord(): void
    {
        $request = $this->makeRequest($this->makeFrontendUser(42));
        $config  = $this->buildDefinitionWithOwnership('fe_user_id');

        self::assertTrue($this->controller->isAllowed(AccessRole::OWNER, $request, ['fe_user_id' => 42], $config));
    }

    #[Test]
    public function ownerRoleDeniesWhenUserDoesNotMatchRecord(): void
    {
        $request = $this->makeRequest($this->makeFrontendUser(42));
        $config  = $this->buildDefinitionWithOwnership('fe_user_id');

        self::assertFalse($this->controller->isAllowed(AccessRole::OWNER, $request, ['fe_user_id' => 99], $config));
    }

    #[Test]
    public function ownerRoleGrantsForBeAdminBypassByDefault(): void
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->user = ['uid' => 1];
        $beUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $beUser;

        // FE user does NOT own the record (uid 42 vs record 99)
        $request = $this->makeRequest($this->makeFrontendUser(42));
        $config  = $this->buildDefinitionWithOwnership('fe_user_id', beAdminBypass: true);

        self::assertTrue($this->controller->isAllowed(AccessRole::OWNER, $request, ['fe_user_id' => 99], $config));
    }

    #[Test]
    public function ownerRoleDeniesForBeAdminWhenBypassDisabled(): void
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->user = ['uid' => 1];
        $beUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->makeRequest($this->makeFrontendUser(42));
        $config  = $this->buildDefinitionWithOwnership('fe_user_id', beAdminBypass: false);

        self::assertFalse($this->controller->isAllowed(AccessRole::OWNER, $request, ['fe_user_id' => 99], $config));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildDefinitionWithOwnership(string $column, bool $beAdminBypass = true): ApiDefinition
    {
        return ApiDefinition::fromArray([
            'general' => [
                'table'        => 'tx_test',
                'resourceName' => 'tests',
                'resourceType' => 'Test',
                'operations'   => ['update'],
            ],
            'security'  => ['update' => AccessRole::OWNER],
            'ownership' => [
                'column'        => $column,
                'beAdminBypass' => $beAdminBypass,
            ],
        ]);
    }

    private function buildDefinitionWithoutOwnership(): ApiDefinition
    {
        return ApiDefinition::fromArray([
            'general' => [
                'table'        => 'tx_test',
                'resourceName' => 'tests',
                'resourceType' => 'Test',
                'operations'   => ['update'],
            ],
        ]);
    }
}
