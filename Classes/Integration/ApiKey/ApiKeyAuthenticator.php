<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Integration\ApiKey;

use MaikSchneider\ApiKey\Authentication\ApiKey;
use MaikSchneider\ApiKey\Authentication\ApiKeyAuthenticatorInterface;
use MaikSchneider\ApiKey\Middleware\ApiKeyAuthentication;
use MaikSchneider\TcaApi\Security\Authentication\AuthenticationResult;
use MaikSchneider\TcaApi\Security\Authentication\Principal;
use MaikSchneider\TcaApi\Security\Authentication\RequestHeaderAuthenticatorInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the principal from a key of the `api_key` extension, sent as
 * `X-API-Key` or `Authorization: Bearer`. Registered only while that extension
 * is installed.
 *
 * A service key becomes a service principal, a key bound to a frontend or
 * backend user becomes that user. The user is read from the database without
 * logging in, so no session or cookie is created. A key with scopes restricts
 * the principal to them; a key without scopes is unrestricted.
 */
final class ApiKeyAuthenticator implements RequestHeaderAuthenticatorInterface
{
    public const SOURCE = 'api_key';

    private const CHALLENGE = 'Bearer realm="tca_api", error="invalid_token", ApiKey realm="tca_api", header="X-API-Key"';

    public function __construct(
        private readonly ApiKeyAuthenticatorInterface $apiKeyAuthenticator,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function authenticate(ServerRequestInterface $request): AuthenticationResult
    {
        $result = ApiKeyAuthentication::getResult($request) ?? $this->apiKeyAuthenticator->authenticate($request);
        if ($result->isInvalid()) {
            return AuthenticationResult::failed(self::CHALLENGE, 'Invalid API key');
        }

        $apiKey = $result->getApiKey();
        if ($apiKey === null) {
            return AuthenticationResult::notApplicable();
        }

        $principal = match (true) {
            $apiKey->frontendUserUid !== null => $this->frontendUserPrincipal($apiKey, $apiKey->frontendUserUid),
            $apiKey->backendUserUid !== null => $this->backendUserPrincipal($apiKey, $apiKey->backendUserUid),
            default => new Principal(
                isService: true,
                serviceLabel: $apiKey->title,
                serviceUid: $apiKey->uid,
                scopes: $this->scopes($apiKey),
                source: self::SOURCE,
                credential: $this->credential($apiKey),
            ),
        };

        return $principal !== null
            ? AuthenticationResult::authenticated($principal)
            : AuthenticationResult::failed(self::CHALLENGE, 'Invalid API key');
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getRequestHeaders(): array
    {
        return ['X-API-Key'];
    }

    private function frontendUserPrincipal(ApiKey $apiKey, int $uid): ?Principal
    {
        $user = $this->fetchUser('fe_users', $uid, ['username', 'usergroup']);
        if ($user === null) {
            return null;
        }

        return new Principal(
            frontendUserUid: $uid,
            frontendUsername: (string)$user['username'],
            frontendGroupIds: GeneralUtility::intExplode(',', (string)$user['usergroup'], true),
            scopes: $this->scopes($apiKey),
            source: self::SOURCE,
            credential: $this->credential($apiKey),
        );
    }

    private function backendUserPrincipal(ApiKey $apiKey, int $uid): ?Principal
    {
        $user = $this->fetchUser('be_users', $uid, ['username', 'admin']);
        if ($user === null) {
            return null;
        }

        return new Principal(
            backendUserUid: $uid,
            backendUsername: (string)$user['username'],
            isBackendAdmin: (bool)$user['admin'],
            scopes: $this->scopes($apiKey),
            source: self::SOURCE,
            credential: $this->credential($apiKey),
        );
    }

    /**
     * The `api_key` extension has already checked that the user is available;
     * the default restrictions only guard against it vanishing in between.
     *
     * @param list<string> $columns
     * @return array<string, mixed>|null
     */
    private function fetchUser(string $table, int $uid, array $columns): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $row = $queryBuilder
            ->select(...$columns)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    /**
     * @return list<string>|null
     */
    private function scopes(ApiKey $apiKey): ?array
    {
        return $apiKey->scopes !== [] ? $apiKey->scopes : null;
    }

    private function credential(ApiKey $apiKey): string
    {
        return sprintf('%s:%d:%s', self::SOURCE, $apiKey->uid, $apiKey->identifier);
    }
}
