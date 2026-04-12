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
            $dataMap = $this->buildDataMap($table, 'NEW_1', $data);
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
            $dataMap = $this->buildDataMap($table, (string)$uid, $data);
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

    private function buildDataMap(string $table, string $recordId, array $data): array
    {
        $dataMap = [$table => [$recordId => []]];
        $newRecordCounter = 0;
        $parentPid = isset($data['pid']) && is_numeric($data['pid']) ? (int)$data['pid'] : 0;

        foreach ($data as $field => $value) {
            if (!\is_array($value)) {
                $dataMap[$table][$recordId][$field] = $value;
                continue;
            }

            $normalization = $this->normalizeRelationValue($table, $field, $value, $newRecordCounter, $parentPid);
            if ($normalization === null) {
                $dataMap[$table][$recordId][$field] = $value;
                continue;
            }

            $relationValue = $normalization['tokens'];
            if ($relationValue !== []) {
                $dataMap[$table][$recordId][$field] = $this->isSingleValueRelation($table, $field)
                    ? $relationValue[0]
                    : implode(',', $relationValue);
            } else {
                $dataMap[$table][$recordId][$field] = '';
            }

            foreach ($normalization['newRecords'] as $newRecord) {
                $dataMap[$newRecord['table']][$newRecord['id']] = $newRecord['data'];
            }
        }

        return $dataMap;
    }

    /**
     * @param int $newRecordCounter
     * @return array{tokens: list<int|string>, newRecords: list<array{table: string, id: string, data: array}>}|null
     */
    private function normalizeRelationValue(
        string $table,
        string $field,
        array $value,
        int &$newRecordCounter,
        int $parentPid,
    ): ?array {
        if (!array_is_list($value)) {
            return null;
        }

        $foreignTable = $this->resolveForeignTable($table, $field);
        if ($foreignTable === null) {
            return null;
        }

        $tokens = [];
        $newRecords = [];

        foreach ($value as $item) {
            if (is_numeric($item)) {
                $tokens[] = (int)$item;
                continue;
            }

            if (!\is_array($item)) {
                continue;
            }

            if (isset($item['uid']) && is_numeric($item['uid'])) {
                $tokens[] = (int)$item['uid'];
                continue;
            }

            $newRecordCounter++;
            $newId = 'NEW_REL_' . $newRecordCounter;
            $tokens[] = $newId;

            $newRecordData = $item;
            unset($newRecordData['uid']);
            if (!isset($newRecordData['pid']) || !is_numeric($newRecordData['pid'])) {
                $newRecordData['pid'] = $this->resolveDefaultPidForNewRelatedRecord($foreignTable, $parentPid);
            } else {
                $newRecordData['pid'] = (int)$newRecordData['pid'];
            }

            $newRecords[] = [
                'table' => $foreignTable,
                'id' => $newId,
                'data' => $newRecordData,
            ];
        }

        return [
            'tokens' => $tokens,
            'newRecords' => $newRecords,
        ];
    }

    private function resolveForeignTable(string $table, string $field): ?string
    {
        $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? null;
        if (!\is_array($fieldConfig)) {
            return null;
        }

        if (($fieldConfig['type'] ?? null) === 'category') {
            return 'sys_category';
        }

        if (($fieldConfig['type'] ?? null) === 'group') {
            $allowed = GeneralUtility::trimExplode(',', (string)($fieldConfig['allowed'] ?? ''), true);
            return count($allowed) === 1 ? $allowed[0] : null;
        }

        $foreignTable = $fieldConfig['foreign_table'] ?? null;
        return \is_string($foreignTable) ? $foreignTable : null;
    }

    private function isSingleValueRelation(string $table, string $field): bool
    {
        $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? null;
        if (!\is_array($fieldConfig)) {
            return false;
        }

        if (($fieldConfig['type'] ?? null) === 'category') {
            return false;
        }

        if (isset($fieldConfig['maxitems'])) {
            return (int)$fieldConfig['maxitems'] === 1;
        }

        return ($fieldConfig['renderType'] ?? null) === 'selectSingle';
    }

    private function resolveDefaultPidForNewRelatedRecord(string $foreignTable, int $parentPid): int
    {
        if ($parentPid > 0) {
            return $parentPid;
        }

        $rootLevel = (int)($GLOBALS['TCA'][$foreignTable]['ctrl']['rootLevel'] ?? 0);
        if ($rootLevel !== 0) {
            return 0;
        }

        return 1;
    }
}
