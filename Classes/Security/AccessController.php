<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security;

use MaikSchneider\TcaApi\Enum\AccessRole;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

class AccessController
{
    public function isAllowed(AccessRole $requiredRole, ServerRequestInterface $request): bool
    {
        return match ($requiredRole) {
            AccessRole::PUBLIC   => true,
            AccessRole::FE_USER  => $this->hasFrontendUser($request),
            AccessRole::BE_USER  => $this->hasBackendUser(),
            AccessRole::BE_ADMIN => $this->isBackendAdmin(),
        };
    }

    private function hasFrontendUser(ServerRequestInterface $request): bool
    {
        $feUser = $request->getAttribute('frontend.user');

        return $feUser !== null && !empty($feUser->user['uid']);
    }

    private function hasBackendUser(): bool
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;

        return $beUser instanceof BackendUserAuthentication
            && is_array($beUser->user)
            && !empty($beUser->user['uid']);
    }

    private function isBackendAdmin(): bool
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;

        return $beUser instanceof BackendUserAuthentication && $beUser->isAdmin();
    }
}
