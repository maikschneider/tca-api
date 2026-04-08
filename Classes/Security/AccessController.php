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
            if ($requiredRole[0] instanceof AccessRole) {
                return $this->hasFrontendUserInGroups($request, $requiredRole[1] ?? []);
            }

            [$class, $method] = $requiredRole;
            return (bool)GeneralUtility::makeInstance($class)->$method($request);
        }

        return match ($requiredRole) {
            AccessRole::PUBLIC   => true,
            AccessRole::FE_USER  => $this->hasFrontendUser($request),
            AccessRole::FE_GROUP => $this->hasFrontendUserInGroups($request, []),
            AccessRole::BE_USER  => $this->hasBackendUser(),
            AccessRole::BE_ADMIN => $this->isBackendAdmin(),
        };
    }

    private function hasFrontendUser(ServerRequestInterface $request): bool
    {
        $feUser = $request->getAttribute('frontend.user');

        return $feUser !== null && !empty($feUser->user['uid']);
    }

    private function hasFrontendUserInGroups(ServerRequestInterface $request, array $allowedGroupIds): bool
    {
        if (!$this->hasFrontendUser($request)) {
            return false;
        }

        $feUser = $request->getAttribute('frontend.user');
        $userGroups = GeneralUtility::intExplode(',', (string)($feUser->user['usergroup'] ?? ''), true);

        if ($userGroups === []) {
            return false;
        }

        if ($allowedGroupIds === []) {
            return true;
        }

        return array_intersect($userGroups, $allowedGroupIds) !== [];
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
