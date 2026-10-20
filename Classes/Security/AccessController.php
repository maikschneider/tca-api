<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Security\Authentication\Principal;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AccessController
{
    /**
     * @param AccessRole|array{0: AccessRole, 1?: array<int>}|array{0: class-string, 1: string} $requiredRole
     */
    public function isAllowed(AccessRole|array $requiredRole, ServerRequestInterface $request, array $record = [], ?ApiDefinition $config = null): bool
    {
        $principal = Principal::fromRequest($request);

        if (is_array($requiredRole)) {
            if ($requiredRole[0] instanceof AccessRole) {
                return $this->isFrontendUserInGroups($principal, $requiredRole[1] ?? []);
            }

            [$class, $method] = $requiredRole;
            return (bool)GeneralUtility::makeInstance($class)->$method($request, $record);
        }

        return match ($requiredRole) {
            AccessRole::PUBLIC   => true,
            AccessRole::DISABLED => false,
            AccessRole::FE_USER  => $principal->isFrontendUser(),
            AccessRole::FE_GROUP => $this->isFrontendUserInGroups($principal, []),
            AccessRole::BE_USER  => $principal->isBackendUser(),
            AccessRole::BE_ADMIN => $principal->isBackendAdmin(),
            AccessRole::OWNER    => $this->isOwner($principal, $record, $config),
        };
    }

    private function isOwner(Principal $principal, array $record, ?ApiDefinition $config): bool
    {
        $ownerColumn = $config?->ownershipColumn;
        if ($ownerColumn === null) {
            return false;
        }

        if ($config->ownershipBeAdminBypass && $principal->isBackendAdmin()) {
            return true;
        }

        if (!$principal->isFrontendUser()) {
            return false;
        }

        return (int)($record[$ownerColumn] ?? null) === $principal->frontendUserUid;
    }

    /**
     * @param array<int> $allowedGroupIds empty means any group
     */
    private function isFrontendUserInGroups(Principal $principal, array $allowedGroupIds): bool
    {
        if (!$principal->isFrontendUser() || $principal->frontendGroupIds === []) {
            return false;
        }

        if ($allowedGroupIds === []) {
            return true;
        }

        return array_intersect($principal->frontendGroupIds, $allowedGroupIds) !== [];
    }
}
