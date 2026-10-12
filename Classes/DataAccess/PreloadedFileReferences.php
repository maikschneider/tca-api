<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use TYPO3\CMS\Core\Resource\FileReference;

/**
 * File references resolved up front for records of a single table.
 *
 * Tracks which records each column was resolved for, so that a record outside
 * that set — an embedded record the preload did not reach, for instance — falls
 * back to a direct lookup instead of silently serializing as "no files".
 */
final readonly class PreloadedFileReferences
{
    /** @var array<string, array<int, list<FileReference>>> column => record uid => references, one entry per covered record */
    private array $references;

    /**
     * @param array<int, true>                                $coveredUids
     * @param array<string, array<int, list<FileReference>>> $references column => record uid => references
     */
    public function __construct(array $coveredUids, array $references)
    {
        $none = array_fill_keys(array_keys($coveredUids), []);

        $this->references = array_map(static fn (array $byUid): array => $byUid + $none, $references);
    }

    /**
     * References for one record, or null when this preload does not cover it and
     * the caller has to resolve them itself.
     *
     * @return list<FileReference>|null
     */
    public function find(string $column, int $uid): ?array
    {
        return $this->references[$column][$uid] ?? null;
    }

    /** A preload covering the records of both, column by column. */
    public function merge(self $other): self
    {
        $references = $this->references;
        foreach ($other->references as $column => $byUid) {
            $references[$column] = ($references[$column] ?? []) + $byUid;
        }

        return new self([], $references);
    }
}
