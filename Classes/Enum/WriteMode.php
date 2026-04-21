<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Enum;

/**
 * Configurable execution strategy for DataHandler writes.
 *
 * ACTING_USER (default): Writes are performed using the authenticated user context.
 *   - Frontend user identity is preserved for audit purposes.
 *   - TYPO3 DataHandler still runs with elevated privileges internally (required
 *     for API-managed tables) but the actor identity is tracked.
 *
 * SYSTEM_ADMIN (opt-in): Writes use a synthetic backend admin user (uid=0, admin=1).
 *   - Bypasses all TYPO3-native ACL boundaries.
 *   - Should only be enabled for trusted, internal-only deployments.
 *   - Requires explicit opt-in via per-resource configuration.
 *
 * WARNING: SYSTEM_ADMIN mode bypasses TYPO3 access control. Only use for internal
 * APIs where the calling application is fully trusted.
 */
enum WriteMode: string
{
    case ACTING_USER = 'acting_user';
    case SYSTEM_ADMIN = 'system_admin';
}
