<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OpenApi;

/**
 * An OpenAPI security scheme, plus which kind of principal a request sent with
 * it can carry. The kinds decide which operations list the scheme in their
 * `security` requirement.
 */
final readonly class SecurityScheme
{
    /**
     * @param array<string, mixed> $definition the OpenAPI Security Scheme Object
     */
    public function __construct(
        public string $name,
        public array $definition,
        public bool $authenticatesFrontendUsers = false,
        public bool $authenticatesBackendUsers = false,
        public bool $isCredential = false,
    ) {
    }
}
