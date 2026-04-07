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
        $dataMap = [$table => ['NEW_1' => $data]];
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, [], $this->makeAdminUser());
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog) {
            throw new \RuntimeException(implode(', ', $dataHandler->errorLog));
        }

        return (int)($dataHandler->substNEWwithIDs['NEW_1'] ?? 0);
    }

    public function update(string $table, int $uid, array $data): void
    {
        $dataMap = [$table => [$uid => $data]];
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, [], $this->makeAdminUser());
        $dataHandler->process_datamap();
    }

    public function delete(string $table, int $uid): void
    {
        $cmdMap = [$table => [$uid => ['delete' => 1]]];
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdMap, $this->makeAdminUser());
        $dataHandler->process_cmdmap();
    }

    private function makeAdminUser(): BackendUserAuthentication
    {
        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $user->user = ['uid' => 0, 'admin' => 1, 'username' => '_tca_api'];
        $user->workspace = 0;
        return $user;
    }
}
