<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security;

/**
 * Controls which tables can be written to via the TCA API.
 *
 * Enforces two complementary lists:
 *   - denyList: system-sensitive tables that must NEVER be written via the API
 *   - allowList: when non-empty, only tables in this list are writable
 *
 * The deny list takes precedence over the allow list.
 */
final class TableAccessControl
{
    /**
     * System-sensitive tables that must never be written through the API.
     * These contain security-critical data (credentials, sessions, permissions).
     *
     * @var string[]
     */
    private const DENIED_TABLES = [
        'be_users',
        'be_groups',
        'be_sessions',
        'fe_sessions',
        'sys_filemounts',
        'sys_be_shortcuts',
        'sys_action',
        'sys_log',
    ];

    /**
     * @param string[] $allowList  Tables explicitly allowed for API writes (empty = all non-denied)
     * @param string[] $denyList   Additional tables to deny (merged with built-in deny list)
     */
    public function __construct(
        private readonly array $allowList = [],
        private readonly array $denyList = [],
    ) {
    }

    /**
     * Check whether write access is permitted for the given table.
     */
    public function isWriteAllowed(string $table): bool
    {
        // Deny list always takes precedence
        if (\in_array($table, self::DENIED_TABLES, true) || \in_array($table, $this->denyList, true)) {
            return false;
        }

        // If an allow list is configured, only those tables are writable
        if ($this->allowList !== []) {
            return \in_array($table, $this->allowList, true);
        }

        return true;
    }

    /**
     * Assert that write access is permitted; throw on violation.
     *
     * @throws \RuntimeException when the table is not writable
     */
    public function assertWriteAllowed(string $table): void
    {
        if (!$this->isWriteAllowed($table)) {
            throw new \RuntimeException(
                sprintf('Write access denied for table "%s". This table is blocked by the TCA API table access control policy.', $table),
                1713680400,
            );
        }
    }
}
