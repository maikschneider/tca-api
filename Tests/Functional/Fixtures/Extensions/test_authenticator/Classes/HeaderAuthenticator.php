<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApiTestAuthenticator;

use MaikSchneider\TcaApi\Security\Authentication\AuthenticationResult;
use MaikSchneider\TcaApi\Security\Authentication\AuthenticatorInterface;
use MaikSchneider\TcaApi\Security\Authentication\Principal;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `X-Test-Auth: fe:<uid>[:<group,group>]`, `be:<uid>[:admin]`, `service:<label>`;
 * any other value fails. `X-Test-Scopes: a:read,b:write` grants scopes; without
 * it the principal is unrestricted.
 */
final class HeaderAuthenticator implements AuthenticatorInterface
{
    public function authenticate(ServerRequestInterface $request): AuthenticationResult
    {
        $header = $request->getHeaderLine('X-Test-Auth');
        if ($header === '') {
            return AuthenticationResult::notApplicable();
        }

        $parts = explode(':', $header);
        $scopes = $request->hasHeader('X-Test-Scopes')
            ? array_values(array_filter(explode(',', $request->getHeaderLine('X-Test-Scopes'))))
            : null;
        $principal = match ($parts[0]) {
            'fe' => new Principal(
                frontendUserUid: (int)($parts[1] ?? 0),
                frontendUsername: 'test-fe',
                frontendGroupIds: array_values(array_map(intval(...), array_filter(explode(',', $parts[2] ?? '')))),
                scopes: $scopes,
                source: 'test',
            ),
            'be' => new Principal(
                backendUserUid: (int)($parts[1] ?? 0),
                backendUsername: 'test-be',
                isBackendAdmin: ($parts[2] ?? '') === 'admin',
                scopes: $scopes,
                source: 'test',
            ),
            'service' => new Principal(isService: true, serviceLabel: $parts[1] ?? '', serviceUid: 1, scopes: $scopes, source: 'test'),
            default => null,
        };

        return $principal !== null
            ? AuthenticationResult::authenticated($principal)
            : AuthenticationResult::failed('Test realm="tca_api"', 'Unknown test credentials');
    }

    public function getPriority(): int
    {
        return 0;
    }
}
