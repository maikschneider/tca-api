<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security;

use MaikSchneider\TcaApi\Enum\WriteMode;
use MaikSchneider\TcaApi\Security\Authentication\Principal;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Factory for creating WriteContext from the current request.
 *
 * Resolves the acting user from the request's principal and applies the
 * configured write mode.
 */
final class WriteContextFactory
{
    /**
     * Build a WriteContext from the current request and configured write mode.
     *
     * Resolution:
     *   - Frontend user → actorType = 'fe_user'
     *   - Backend user  → actorType = 'be_user'
     *   - Service       → actorType = the principal's source, e.g. 'api_key' ('service' without one)
     *   - Anonymous     → actorType = 'system' (system mode forced)
     *
     * The credential the request carried is recorded alongside, so writes by a
     * user-bound API key stay attributable to the key.
     */
    public function fromRequest(ServerRequestInterface $request, WriteMode $mode = WriteMode::ACTING_USER): WriteContext
    {
        $principal = Principal::fromRequest($request);

        return match (true) {
            $principal->isFrontendUser() => WriteContext::forFrontendUser(
                uid: $principal->frontendUserUid,
                username: $principal->frontendUsername !== '' ? $principal->frontendUsername : 'unknown',
                mode: $mode,
                credential: $principal->credential,
            ),
            $principal->isBackendUser() => WriteContext::forBackendUser(
                uid: $principal->backendUserUid,
                username: $principal->backendUsername !== '' ? $principal->backendUsername : 'unknown',
                mode: $mode,
                credential: $principal->credential,
            ),
            $principal->isService => WriteContext::forService(
                uid: $principal->serviceUid,
                username: $principal->serviceLabel !== '' ? $principal->serviceLabel : $principal->source,
                mode: $mode,
                actorType: $principal->source !== '' ? $principal->source : 'service',
                credential: $principal->credential,
            ),
            default => WriteContext::forSystem(),
        };
    }
}
