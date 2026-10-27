<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security;

use MaikSchneider\TcaApi\Enum\WriteMode;

/**
 * Immutable value object carrying actor identity and write mode for DataHandler operations.
 *
 * Used by DataWriteService to determine execution strategy and by WriteAuditLogger
 * to record who performed each write.
 */
final readonly class WriteContext
{
    /**
     * @param WriteMode   $mode           Execution strategy for this write
     * @param string      $actorType      Actor type: 'fe_user', 'be_user', 'system', or the
     *                                    authenticator source of a service principal (e.g. 'api_key')
     * @param int         $actorUid       Actor UID (0 for anonymous/system)
     * @param string      $actorUsername  Human-readable actor identifier for audit
     * @param string      $credential     Safe-to-log identifier of the credential the
     *                                    request carried, e.g. an API key; empty for sessions
     */
    public function __construct(
        public WriteMode $mode,
        public string $actorType,
        public int $actorUid,
        public string $actorUsername,
        public string $credential = '',
    ) {
    }

    /**
     * Create context for an authenticated frontend user.
     */
    public static function forFrontendUser(int $uid, string $username, WriteMode $mode = WriteMode::ACTING_USER, string $credential = ''): self
    {
        return new self(
            mode: $mode,
            actorType: 'fe_user',
            actorUid: $uid,
            actorUsername: $username,
            credential: $credential,
        );
    }

    /**
     * Create context for an authenticated backend user.
     */
    public static function forBackendUser(int $uid, string $username, WriteMode $mode = WriteMode::ACTING_USER, string $credential = ''): self
    {
        return new self(
            mode: $mode,
            actorType: 'be_user',
            actorUid: $uid,
            actorUsername: $username,
            credential: $credential,
        );
    }

    /**
     * Create context for an authenticated service principal without a TYPO3 user
     * record, e.g. a service API key.
     */
    public static function forService(int $uid, string $username, WriteMode $mode = WriteMode::ACTING_USER, string $actorType = 'service', string $credential = ''): self
    {
        return new self(
            mode: $mode,
            actorType: $actorType,
            actorUid: $uid,
            actorUsername: $username,
            credential: $credential,
        );
    }

    /**
     * Create context for system-level writes (no real user).
     *
     * WARNING: This bypasses user-level audit tracking. Only use for internal operations
     * where no user session exists.
     */
    public static function forSystem(): self
    {
        return new self(
            mode: WriteMode::SYSTEM_ADMIN,
            actorType: 'system',
            actorUid: 0,
            actorUsername: '_tca_api_system',
        );
    }

    public function isSystemMode(): bool
    {
        return $this->mode === WriteMode::SYSTEM_ADMIN;
    }
}
