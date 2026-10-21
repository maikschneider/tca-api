<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security\Authentication;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the caller of an API request. Implementations are discovered
 * automatically and asked in descending priority until one is applicable.
 *
 * The built-in session authenticator uses a negative priority, so credentials
 * sent with the request (API keys, tokens) are checked before a session.
 */
interface AuthenticatorInterface
{
    public function authenticate(ServerRequestInterface $request): AuthenticationResult;

    public function getPriority(): int;
}
