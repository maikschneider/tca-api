<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security\Authentication;

/**
 * An authenticator that reads credentials from custom request headers. The
 * headers are added to `Access-Control-Allow-Headers`, so browser clients can
 * send them through a CORS preflight.
 */
interface RequestHeaderAuthenticatorInterface extends AuthenticatorInterface
{
    /**
     * @return list<string>
     */
    public function getRequestHeaders(): array;
}
