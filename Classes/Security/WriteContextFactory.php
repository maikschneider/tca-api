<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security;

use MaikSchneider\TcaApi\Enum\WriteMode;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Factory for creating WriteContext from the current request.
 *
 * Resolves the acting user from the PSR-7 request attributes (frontend.user
 * or BE_USER) and applies the configured write mode.
 */
final class WriteContextFactory
{
    /**
     * Build a WriteContext from the current request and configured write mode.
     *
     * Resolution order:
     *   1. Authenticated frontend user → actorType = 'fe_user'
     *   2. Authenticated backend user  → actorType = 'be_user'
     *   3. No user context             → actorType = 'system' (system mode forced)
     */
    public function fromRequest(ServerRequestInterface $request, WriteMode $mode = WriteMode::ACTING_USER): WriteContext
    {
        // Try frontend user first
        $feUser = $request->getAttribute('frontend.user');
        if ($feUser !== null && !empty($feUser->user['uid'])) {
            return WriteContext::forFrontendUser(
                uid: (int)$feUser->user['uid'],
                username: (string)($feUser->user['username'] ?? 'unknown'),
                mode: $mode,
            );
        }

        // Try backend user
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if ($beUser instanceof BackendUserAuthentication && is_array($beUser->user) && !empty($beUser->user['uid'])) {
            return WriteContext::forBackendUser(
                uid: (int)$beUser->user['uid'],
                username: (string)($beUser->user['username'] ?? 'unknown'),
                mode: $mode,
            );
        }

        // No authenticated user — force system mode
        return WriteContext::forSystem();
    }
}
