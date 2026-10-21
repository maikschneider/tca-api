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
     * @param AccessRole|array{0: AccessRole, 1?: array<int|string>}|array{0: class-string, 1: string} $requiredRole
     */
    public function isAllowed(AccessRole|array $requiredRole, ServerRequestInterface $request, array $record = [], ?ApiDefinition $config = null): bool
    {
        $principal = Principal::fromRequest($request);

        if (is_array($requiredRole)) {
            if ($requiredRole[0] === AccessRole::API_KEY) {
                return $this->isCredentialWithScopes($principal, $requiredRole[1] ?? []);
            }
            if ($requiredRole[0] instanceof AccessRole) {
                return $this->isFrontendUserInGroups($principal, array_map(intval(...), $requiredRole[1] ?? []));
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
            AccessRole::API_KEY  => $principal->isCredential(),
        };
    }

    /**
     * Scopes always restrict: a principal with granted scopes may perform only
     * the operations they cover, whatever its role allows. Sessions carry no
     * scopes and are unaffected.
     */
    public function isOperationInScope(ServerRequestInterface $request, string $resourceName, string $operation): bool
    {
        $scopes = Principal::fromRequest($request)->scopes;

        return $scopes === null || Scope::grants($scopes, Scope::forOperation($resourceName, $operation));
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
     * @param array<int|string> $requiredScopes
     */
    private function isCredentialWithScopes(Principal $principal, array $requiredScopes): bool
    {
        if (!$principal->isCredential()) {
            return false;
        }
        if ($principal->scopes === null) {
            return true;
        }

        foreach ($requiredScopes as $requiredScope) {
            if (!Scope::grants($principal->scopes, (string)$requiredScope)) {
                return false;
            }
        }

        return true;
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
