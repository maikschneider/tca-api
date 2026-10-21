<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Dispatcher\RequestContext;
use MaikSchneider\TcaApi\Enum\AccessRole;
use MaikSchneider\TcaApi\Event\ModifyOpenApiSecuritySchemesEvent;
use MaikSchneider\TcaApi\Security\Scope;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Builds `components.securitySchemes` and the per-operation `security`
 * requirement from the access role of each operation.
 */
final readonly class OpenApiSecurityBuilder
{
    public function __construct(private EventDispatcherInterface $eventDispatcher)
    {
    }

    /**
     * @param array<string, ApiDefinition> $resources
     * @return array<string, SecurityScheme>
     */
    public function collectSchemes(array $resources, RequestContext $ctx): array
    {
        $frontendCookie = (string)($GLOBALS['TYPO3_CONF_VARS']['FE']['cookieName'] ?? 'fe_typo_user');
        $backendCookie = (string)($GLOBALS['TYPO3_CONF_VARS']['BE']['cookieName'] ?? 'be_typo_user');

        $schemes = [
            'FrontendSession' => new SecurityScheme(
                'FrontendSession',
                ['type' => 'apiKey', 'in' => 'cookie', 'name' => $frontendCookie, 'description' => 'TYPO3 frontend user session.'],
                authenticatesFrontendUsers: true,
            ),
            'BackendSession' => new SecurityScheme(
                'BackendSession',
                ['type' => 'apiKey', 'in' => 'cookie', 'name' => $backendCookie, 'description' => 'TYPO3 backend user session.'],
                authenticatesBackendUsers: true,
            ),
        ];

        $event = new ModifyOpenApiSecuritySchemesEvent($schemes, $resources, $ctx);
        $this->eventDispatcher->dispatch($event);

        return $event->getSchemes();
    }

    /**
     * @param array<string, SecurityScheme> $schemes
     * @return array<string, array<string, mixed>>
     */
    public function buildComponents(array $schemes): array
    {
        return array_map(static fn (SecurityScheme $scheme): array => $scheme->definition, $schemes);
    }

    /**
     * Each entry of the returned list is one alternative way to authenticate.
     * An empty list means the operation is public; `null` means no registered
     * scheme can satisfy the role, so no requirement is emitted.
     *
     * @param array<string, SecurityScheme> $schemes
     * @return list<array<string, list<string>>|\stdClass>|null
     */
    public function buildRequirement(mixed $role, string $resourceName, string $operation, array $schemes, bool $ownershipBeAdminBypass = true): ?array
    {
        $accessRole = $role instanceof AccessRole ? $role : (\is_array($role) && $role[0] instanceof AccessRole ? $role[0] : null);
        if ($accessRole === AccessRole::PUBLIC) {
            return [];
        }
        if ($accessRole === AccessRole::DISABLED) {
            return null;
        }

        $scopes = [Scope::forOperation($resourceName, $operation)];
        if ($accessRole === AccessRole::API_KEY && \is_array($role)) {
            $scopes = array_values(array_unique([...$scopes, ...array_map('strval', $role[1] ?? [])]));
        }

        $requirement = [];
        foreach ($schemes as $scheme) {
            if ($this->canSatisfy($scheme, $accessRole, $ownershipBeAdminBypass)) {
                $requirement[] = [$scheme->name => $scheme->isCredential ? $scopes : []];
            }
        }
        if ($accessRole === null) {
            // A callable voter may admit anonymous requests too; `{}` is OpenAPI's
            // anonymous alternative, so it must encode as an object.
            $requirement[] = new \stdClass();
        }

        return $requirement !== [] ? $requirement : null;
    }

    private function canSatisfy(SecurityScheme $scheme, ?AccessRole $role, bool $ownershipBeAdminBypass): bool
    {
        return match ($role) {
            AccessRole::FE_USER, AccessRole::FE_GROUP => $scheme->authenticatesFrontendUsers,
            // A backend admin passes an owner check only through the ownership bypass.
            AccessRole::OWNER => $scheme->authenticatesFrontendUsers || ($ownershipBeAdminBypass && $scheme->authenticatesBackendUsers),
            AccessRole::BE_USER, AccessRole::BE_ADMIN => $scheme->authenticatesBackendUsers,
            AccessRole::API_KEY => $scheme->isCredential,
            default => true,
        };
    }
}
