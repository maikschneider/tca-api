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
        $adminUser = $this->makeAdminUser();
        return $this->withGlobalBackendUser($adminUser, function () use ($table, $data, $adminUser) {
            $dataMap = [$table => ['NEW_1' => $data]];
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($dataMap, [], $adminUser);
            $dataHandler->process_datamap();

            if ($dataHandler->errorLog) {
                throw new \RuntimeException(implode(', ', $dataHandler->errorLog));
            }

            return (int)($dataHandler->substNEWwithIDs['NEW_1'] ?? 0);
        });
    }

    public function update(string $table, int $uid, array $data): void
    {
        $adminUser = $this->makeAdminUser();
        $this->withGlobalBackendUser($adminUser, function () use ($table, $uid, $data, $adminUser) {
            $dataMap = [$table => [$uid => $data]];
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($dataMap, [], $adminUser);
            $dataHandler->process_datamap();
        });
    }

    public function delete(string $table, int $uid): void
    {
        $adminUser = $this->makeAdminUser();
        $this->withGlobalBackendUser($adminUser, function () use ($table, $uid, $adminUser) {
            $cmdMap = [$table => [$uid => ['delete' => 1]]];
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], $cmdMap, $adminUser);
            $dataHandler->process_cmdmap();
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
