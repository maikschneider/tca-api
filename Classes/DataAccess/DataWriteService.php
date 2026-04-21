<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Enum\WriteMode;
use MaikSchneider\TcaApi\Security\TableAccessControl;
use MaikSchneider\TcaApi\Security\WriteAuditLogger;
use MaikSchneider\TcaApi\Security\WriteContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Write operations via TYPO3 DataHandler.
 *
 * DataHandler ensures: crdate/tstamp auto-set, soft-delete via deleted=1,
 * workspace drafts, reference index update, cache clear, processDatamap hooks.
 *
 * SECURITY: By default (WriteMode::ACTING_USER), writes track the real actor
 * identity for audit purposes. The synthetic admin user is only used when
 * explicitly opted-in via WriteMode::SYSTEM_ADMIN.
 *
 * WARNING: SYSTEM_ADMIN mode bypasses TYPO3 access control. Only enable for
 * internal APIs where the calling application is fully trusted.
 */
final class DataWriteService
{
    public function __construct(
        private readonly TableAccessControl $tableAccessControl,
        private readonly WriteAuditLogger $auditLogger,
    ) {
    }

    /**
     * Process a multi-table datamap in a single DataHandler call.
     *
     * Accepts a map of table => [uid_or_NEW_placeholder => data]. DataHandler
     * resolves NEW_xxx placeholders across all tables in the map, enabling
     * creation of parent and related records atomically with correct cross-references.
     *
     * Returns the substNEWwithIDs array: NEW_xxx => resolved integer UID.
     *
     * @param array<string, array<string|int, array>> $dataMap
     * @param WriteContext|null $context  Actor context; defaults to system admin for BC
     * @return array<string, int>
     */
    public function processDataMap(array $dataMap, ?WriteContext $context = null): array
    {
        $context = $context ?? WriteContext::forSystem();

        // Enforce table access control for every table in the datamap
        foreach (array_keys($dataMap) as $table) {
            $this->assertTableAccess('write', (string)$table, $context);
        }

        $this->auditLogDataMap('create/update', $dataMap, $context);

        $adminUser = $this->makeWriteUser($context);
        return $this->withGlobalBackendUser($adminUser, function () use ($dataMap, $adminUser): array {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($dataMap, [], $adminUser);
            $dataHandler->process_datamap();

            if ($dataHandler->errorLog) {
                throw new \RuntimeException('DataHandler process failed: ' . implode(', ', $dataHandler->errorLog));
            }

            return $dataHandler->substNEWwithIDs;
        });
    }

    /**
     * @param WriteContext|null $context  Actor context; defaults to system admin for BC
     */
    public function delete(string $table, int $uid, ?WriteContext $context = null): void
    {
        $context = $context ?? WriteContext::forSystem();

        $this->assertTableAccess('delete', $table, $context);
        $this->auditLogger->logWrite('delete', $table, $uid, $context);

        $adminUser = $this->makeWriteUser($context);
        $this->withGlobalBackendUser($adminUser, function () use ($table, $uid, $adminUser): void {
            $cmdMap = [$table => [$uid => ['delete' => 1]]];
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], $cmdMap, $adminUser);
            $dataHandler->process_cmdmap();

            if ($dataHandler->errorLog) {
                throw new \RuntimeException('DataHandler delete failed: ' . implode(', ', $dataHandler->errorLog));
            }
        });
    }

    /**
     * Execute a callback with the given backend user set as $GLOBALS['BE_USER'].
     *
     * TYPO3 v14 DataHandler logging calls BackendUserAuthentication::isSystemMaintainer()
     * which accesses $GLOBALS['BE_USER'] directly. The global must be set to avoid
     * null-pointer errors when DataHandler is used outside a real backend session.
     *
     * @template T
     * @param BackendUserAuthentication $user
     * @param \Closure(): T $callback
     * @return T
     */
    private function withGlobalBackendUser(BackendUserAuthentication $user, \Closure $callback): mixed
    {
        $previousBeUser = $GLOBALS['BE_USER'] ?? null;
        $GLOBALS['BE_USER'] = $user;
        try {
            return $callback();
        } finally {
            if ($previousBeUser === null) {
                unset($GLOBALS['BE_USER']);
            } else {
                $GLOBALS['BE_USER'] = $previousBeUser;
            }
        }
    }

    /**
     * Create the backend user for DataHandler execution.
     *
     * In ACTING_USER mode the user carries the real actor's identity metadata
     * but still has admin=1 because DataHandler requires admin for API-managed
     * tables that typically lack per-user BE permissions.
     *
     * In SYSTEM_ADMIN mode the synthetic system user is used with no user identity.
     */
    private function makeWriteUser(WriteContext $context): BackendUserAuthentication
    {
        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        if ($context->mode === WriteMode::ACTING_USER) {
            // Sanitize username for safe embedding in synthetic BE user record
            $safeUsername = str_replace(['[', ']', ':'], ['_', '_', '_'], $context->actorUsername);
            $user->user = [
                'uid' => 0,
                'admin' => 1,
                'username' => sprintf('_tca_api[%s:%d:%s]', $context->actorType, $context->actorUid, $safeUsername),
            ];
        } else {
            $user->user = ['uid' => 0, 'admin' => 1, 'username' => '_tca_api_system'];
        }

        $user->workspace = 0;
        $user->initializeUserSessionManager();
        return $user;
    }

    private function assertTableAccess(string $operation, string $table, WriteContext $context): void
    {
        if (!$this->tableAccessControl->isWriteAllowed($table)) {
            $reason = 'Table blocked by access control policy';
            $this->auditLogger->logDenied($operation, $table, $context, $reason);
            $this->tableAccessControl->assertWriteAllowed($table);
        }
    }

    /**
     * Audit-log all tables/UIDs in a datamap.
     */
    private function auditLogDataMap(string $operation, array $dataMap, WriteContext $context): void
    {
        foreach ($dataMap as $table => $records) {
            foreach (array_keys($records) as $uid) {
                $this->auditLogger->logWrite($operation, (string)$table, $uid, $context);
            }
        }
    }
}
