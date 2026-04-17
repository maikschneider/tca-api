<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\FileUpload;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\Field\FileFieldType;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

final class FileOwnershipService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TcaSchemaFactory $schemaFactory,
    ) {
    }

    /**
     * Returns true when the FE user may associate the given sys_file UID with a record.
     *
     * Passes when:
     * - $feUserUid is 0 (no FE session — skip check)
     * - tx_tcaapi_owner is 0/null (unclaimed file)
     * - tx_tcaapi_owner === $feUserUid (same owner)
     */
    public function isFileAccessible(int $fileUid, int $feUserUid): bool
    {
        if ($feUserUid === 0) {
            return true;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $row = $qb->select('tx_tcaapi_owner')
            ->from('sys_file_metadata')
            ->where($qb->expr()->eq('file', $qb->createNamedParameter($fileUid)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            // No metadata record yet — file is unclaimed
            return true;
        }

        $owner = (int)($row['tx_tcaapi_owner'] ?? 0);
        return $owner === 0 || $owner === $feUserUid;
    }

    /**
     * Scans $data for TCA type=file columns and validates each referenced file UID.
     *
     * Returns a list of inaccessible sys_file UIDs, empty array on success.
     *
     * @return list<int>
     */
    public function checkFileColumns(array $data, string $table, int $feUserUid): array
    {
        if ($feUserUid === 0) {
            return [];
        }

        if (!$this->schemaFactory->has($table)) {
            return [];
        }

        $schema = $this->schemaFactory->get($table);
        $failed = [];

        foreach ($data as $column => $value) {
            if (!$schema->hasField($column)) {
                continue;
            }

            $field = $schema->getField($column);
            if (!($field instanceof FileFieldType)) {
                continue;
            }

            foreach ($this->extractFileUids($value) as $uid) {
                if (!$this->isFileAccessible($uid, $feUserUid)) {
                    $failed[] = $uid;
                }
            }
        }

        return $failed;
    }

    /**
     * Extracts sys_file UIDs from a column value (array or comma-separated string).
     *
     * @return list<int>
     */
    private function extractFileUids(mixed $value): array
    {
        $uids = is_array($value)
            ? array_map('intval', $value)
            : array_filter(array_map('intval', explode(',', (string)$value)));

        return array_values(array_filter($uids, static fn(int $uid) => $uid > 0));
    }
}
