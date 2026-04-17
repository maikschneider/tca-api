<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Write operations via TYPO3 DataHandler.
 *
 * DataHandler ensures: crdate/tstamp auto-set, soft-delete via deleted=1,
 * workspace drafts, reference index update, cache clear, processDatamap hooks.
 */
final class DataWriteService
{
    public function create(string $table, array $data): int
    {
        try {
            $substMap = $this->processDataMap([$table => ['NEW_1' => $data]]);
            return (int)($substMap['NEW_1'] ?? 0);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('DataHandler create failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function update(string $table, int $uid, array $data): void
    {
        try {
            $this->processDataMap([$table => [$uid => $data]]);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('DataHandler update failed: ' . $e->getMessage(), 0, $e);
        }
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
     * @return array<string, int>
     */
    public function processDataMap(array $dataMap): array
    {
        $adminUser = $this->makeAdminUser();
        return $this->withGlobalBackendUser($adminUser, function () use ($dataMap, $adminUser): array {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($dataMap, [], $adminUser);
            $dataHandler->process_datamap();

            if ($dataHandler->errorLog) {
                throw new \RuntimeException(implode(', ', $dataHandler->errorLog));
            }

            return $dataHandler->substNEWwithIDs;
        });
    }

    public function delete(string $table, int $uid): void
    {
        $adminUser = $this->makeAdminUser();
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

    private function makeAdminUser(): BackendUserAuthentication
    {
        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $user->user = ['uid' => 0, 'admin' => 1, 'username' => '_tca_api'];
        $user->workspace = 0;
        $user->initializeUserSessionManager();
        return $user;
    }
}
