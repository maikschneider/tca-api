<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security;

use Psr\Log\LoggerInterface;

/**
 * Audit logger for all write operations performed through the TCA API.
 *
 * Records actor identity, operation type, target table/UID, and execution mode.
 * This enables compliance auditing and traceability for all mutations.
 */
final class WriteAuditLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Log a write operation (create, update, delete).
     *
     * @param string       $operation  'create', 'update', or 'delete'
     * @param string       $table      Target database table
     * @param int|string   $uid        Record UID (0 or NEW_xxx for creates before persistence)
     * @param WriteContext $context    Actor and execution context
     */
    public function logWrite(string $operation, string $table, int|string $uid, WriteContext $context): void
    {
        $this->logger->info('TCA API write operation', [
            'operation' => $operation,
            'table' => $table,
            'uid' => $uid,
            'actor_type' => $context->actorType,
            'actor_uid' => $context->actorUid,
            'actor_username' => $context->actorUsername,
            'write_mode' => $context->mode->value,
        ]);
    }

    /**
     * Log when a write is denied by table access control.
     */
    public function logDenied(string $operation, string $table, WriteContext $context, string $reason): void
    {
        $this->logger->warning('TCA API write denied', [
            'operation' => $operation,
            'table' => $table,
            'actor_type' => $context->actorType,
            'actor_uid' => $context->actorUid,
            'actor_username' => $context->actorUsername,
            'write_mode' => $context->mode->value,
            'reason' => $reason,
        ]);
    }
}
