<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security;

use MaikSchneider\TcaApi\Enum\AccessRole;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class AccessController
{
    public function isAllowed(AccessRole|array $requiredRole, ServerRequestInterface $request): bool
    {
        if (is_array($requiredRole)) {
            [$class, $method] = $requiredRole;
            return (bool)GeneralUtility::makeInstance($class)->$method($request);
        }

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
