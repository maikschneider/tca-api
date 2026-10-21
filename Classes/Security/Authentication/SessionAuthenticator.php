<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security\Authentication;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The frontend and backend user that core's authentication middlewares
 * restored from their session cookies.
 */
final class SessionAuthenticator implements AuthenticatorInterface
{
    public function authenticate(ServerRequestInterface $request): AuthenticationResult
    {
        $frontendUser = $request->getAttribute('frontend.user')?->user;
        if (!\is_array($frontendUser) || empty($frontendUser['uid'])) {
            $frontendUser = null;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !\is_array($backendUser->user) || empty($backendUser->user['uid'])) {
            $backendUser = null;
        }

        if ($frontendUser === null && $backendUser === null) {
            return AuthenticationResult::notApplicable();
        }

        return AuthenticationResult::authenticated(new Principal(
            frontendUserUid: (int)($frontendUser['uid'] ?? 0),
            frontendUsername: (string)($frontendUser['username'] ?? ''),
            frontendGroupIds: GeneralUtility::intExplode(',', (string)($frontendUser['usergroup'] ?? ''), true),
            backendUserUid: (int)($backendUser?->user['uid'] ?? 0),
            backendUsername: (string)($backendUser?->user['username'] ?? ''),
            isBackendAdmin: (bool)$backendUser?->isAdmin(),
            source: 'session',
        ));
    }

    public function getPriority(): int
    {
        return -10;
    }
}
