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
     *   - Service       → actorType = 'service'
     *   - Anonymous     → actorType = 'system' (system mode forced)
     */
    public function fromRequest(ServerRequestInterface $request, WriteMode $mode = WriteMode::ACTING_USER): WriteContext
    {
        $principal = Principal::fromRequest($request);

        return match (true) {
            $principal->isFrontendUser() => WriteContext::forFrontendUser(
                uid: $principal->frontendUserUid,
                username: $principal->frontendUsername !== '' ? $principal->frontendUsername : 'unknown',
                mode: $mode,
            ),
            $principal->isBackendUser() => WriteContext::forBackendUser(
                uid: $principal->backendUserUid,
                username: $principal->backendUsername !== '' ? $principal->backendUsername : 'unknown',
                mode: $mode,
            ),
            $principal->isService => WriteContext::forService(
                uid: $principal->serviceUid,
                username: $principal->serviceLabel !== '' ? $principal->serviceLabel : $principal->source,
                mode: $mode,
            ),
            default => WriteContext::forSystem(),
        };
    }
}
