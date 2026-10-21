<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security\Authentication;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The caller of an API request, resolved once per request by the authenticator
 * chain and stored as the `tca_api.principal` request attribute.
 *
 * A principal can carry a frontend and a backend user at the same time, because
 * a browser may hold both sessions. A service principal has neither: it is an
 * authenticated caller without a TYPO3 user record, e.g. a service API key.
 */
final readonly class Principal
{
    public const REQUEST_ATTRIBUTE = 'tca_api.principal';

    /**
     * @param list<int> $frontendGroupIds
     * @param list<string>|null $scopes granted scopes; `null` means unrestricted
     * @param string $source identifies the authenticator, e.g. `session` or `api_key`
     */
    public function __construct(
        public int $frontendUserUid = 0,
        public string $frontendUsername = '',
        public array $frontendGroupIds = [],
        public int $backendUserUid = 0,
        public string $backendUsername = '',
        public bool $isBackendAdmin = false,
        public bool $isService = false,
        public string $serviceLabel = '',
        public int $serviceUid = 0,
        public ?array $scopes = null,
        public string $source = '',
    ) {
    }

    public static function anonymous(): self
    {
        return new self();
    }

    /**
     * Reads the principal resolved by the dispatcher. Requests that never passed
     * the dispatcher (custom handlers called directly, unit tests) fall back to
     * the frontend and backend session.
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        $principal = $request->getAttribute(self::REQUEST_ATTRIBUTE);
        if ($principal instanceof self) {
            return $principal;
        }

        return (new SessionAuthenticator())->authenticate($request)->principal ?? self::anonymous();
    }

    public function isAuthenticated(): bool
    {
        return $this->isFrontendUser() || $this->isBackendUser() || $this->isService;
    }

    public function isFrontendUser(): bool
    {
        return $this->frontendUserUid > 0;
    }

    public function isBackendUser(): bool
    {
        return $this->backendUserUid > 0;
    }

    public function isBackendAdmin(): bool
    {
        return $this->isBackendUser() && $this->isBackendAdmin;
    }
}
