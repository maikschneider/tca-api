<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security\Authentication;

/**
 * Outcome of one authenticator: no credentials of its kind (the chain continues),
 * a resolved principal, or credentials that were present but invalid.
 */
final readonly class AuthenticationResult
{
    private function __construct(
        public ?Principal $principal,
        public bool $failed,
        public string $challenge,
        public string $reason,
    ) {
    }

    public static function notApplicable(): self
    {
        return new self(null, false, '', '');
    }

    public static function authenticated(Principal $principal): self
    {
        return new self($principal, false, '', '');
    }

    /**
     * @param string $challenge value of the `WWW-Authenticate` header, e.g. `Bearer realm="api"`
     */
    public static function failed(string $challenge, string $reason = 'Invalid credentials'): self
    {
        return new self(null, true, $challenge, $reason);
    }

    public function isApplicable(): bool
    {
        return $this->failed || $this->principal !== null;
    }
}
