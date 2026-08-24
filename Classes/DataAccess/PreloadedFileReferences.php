<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use TYPO3\CMS\Core\Resource\FileReference;

/**
 * File references resolved up front for one page of records of a single table.
 *
 * Carries the set of record UIDs it was built for so that a record outside that
 * set — an embedded record of the same table, for instance — falls back to a
 * direct lookup instead of silently serializing as "no files".
 */
final readonly class PreloadedFileReferences
{
    /**
     * @param array<int, true>                                $coveredUids
     * @param array<string, array<int, list<FileReference>>> $references column => record uid => references
     */
    public function __construct(
        private array $coveredUids,
        private array $references,
    ) {
    }

    /**
     * References for one record, or null when this preload does not cover it and
     * the caller has to resolve them itself.
     *
     * @return list<FileReference>|null
     */
    public function find(string $column, int $uid): ?array
    {
        if (!isset($this->coveredUids[$uid], $this->references[$column])) {
            return null;
        }

        return $this->references[$column][$uid] ?? [];
    }
}
