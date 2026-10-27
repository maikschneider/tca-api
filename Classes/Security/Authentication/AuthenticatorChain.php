<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security\Authentication;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[Autoconfigure(public: true)]
final class AuthenticatorChain
{
    /** @var list<AuthenticatorInterface> */
    private array $authenticators;

    /**
     * @param iterable<AuthenticatorInterface> $authenticators
     */
    public function __construct(
        #[AutowireIterator('tca_api.authenticator')]
        iterable $authenticators,
    ) {
        $all = [];
        foreach ($authenticators as $authenticator) {
            $all[] = $authenticator;
        }

        usort($all, static fn (AuthenticatorInterface $a, AuthenticatorInterface $b): int => $b->getPriority() <=> $a->getPriority());

        $this->authenticators = $all;
    }

    /**
     * Returns the first applicable result. A request without any credentials
     * authenticates as anonymous.
     */
    public function authenticate(ServerRequestInterface $request): AuthenticationResult
    {
        foreach ($this->authenticators as $authenticator) {
            $result = $authenticator->authenticate($request);
            if ($result->isApplicable()) {
                return $result;
            }
        }

        return AuthenticationResult::authenticated(Principal::anonymous());
    }

    /**
     * Request headers the registered authenticators read credentials from.
     *
     * @return list<string>
     */
    public function getRequestHeaders(): array
    {
        $headers = [];
        foreach ($this->authenticators as $authenticator) {
            if ($authenticator instanceof RequestHeaderAuthenticatorInterface) {
                array_push($headers, ...$authenticator->getRequestHeaders());
            }
        }

        return $headers;
    }
}
