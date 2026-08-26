<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Turns `type=file` column input into sys_file_reference data for an existing FAL file.
 *
 * A `type=file` column stores the reference count, and the link lives in
 * sys_file_reference — which cannot be written in the same DataHandler pass as
 * the parent, because uid_foreign is unknown until the parent exists. Uploads
 * already solve that with a second processDataMap call; this produces the same
 * shape from a JSON body so linking an existing file takes the same route.
 *
 * Accepted per column:
 *   "photo": 12                                  — one file
 *   "photo": [12, 15]                            — several
 *   "photo": [{"fileUid": 12, "title": "Hero"}]  — with reference overrides
 *   "photo": []                                  — detach everything
 */
#[Autoconfigure(public: true)]
final readonly class FileReferenceInputResolver
{
    /**
     * Reference-local overrides a client may set. Everything else on
     * sys_file_reference is either bookkeeping or set by this resolver.
     */
    private const WRITABLE_REFERENCE_FIELDS = ['title', 'description', 'alternative', 'link', 'crop'];

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    public function resolve(array $body, ApiDefinition $config): ResolvedFileReferenceInput
    {
        $remainingBody = $body;
        $references    = [];
        $violations    = [];

        foreach ($body as $column => $value) {
            if (($GLOBALS['TCA'][$config->table]['columns'][$column]['config']['type'] ?? '') !== 'file') {
                continue;
            }
            if ($config->isExplicitMode && $config->getColumn($column)?->isWritable() !== true) {
                continue;
            }

            unset($remainingBody[$column]);

            $items = $this->normalizeItems($value);
            if ($items === null) {
                $violations[] = $this->violation($column, null, 'must be a file uid, a list of file uids, or a list of objects carrying "fileUid".', 'INVALID_FILE_INPUT');
                continue;
            }

            $maxItems = (int)($GLOBALS['TCA'][$config->table]['columns'][$column]['config']['maxitems'] ?? 0);
            if ($maxItems > 0 && \count($items) > $maxItems) {
                $violations[] = $this->violation($column, null, sprintf('accepts at most %d file(s), %d given.', $maxItems, \count($items)), 'TOO_MANY_FILES');
                continue;
            }

            $columnRefs = [];
            foreach ($items as $index => $item) {
                $fileUid = $this->fileUid($item);

                if ($fileUid === null) {
                    $violations[] = $this->violation($column, $index, 'must carry a numeric "fileUid".', 'INVALID_FILE_INPUT');
                    continue;
                }
                if (!$this->fileExists($fileUid)) {
                    $violations[] = $this->violation($column, $index, sprintf('references file %d, which does not exist.', $fileUid), 'FILE_NOT_FOUND');
                    continue;
                }

                $columnRefs[StringUtility::getUniqueId('NEW_ref')] = [
                    'uid_local'   => $fileUid,
                    'tablenames'  => $config->table,
                    'fieldname'   => $column,
                    'table_local' => 'sys_file',
                    'hidden'      => 0,
                    'pid'         => $config->storagePid ?? 0,
                ] + $this->referenceOverrides($item);
            }

            // Kept even when empty: an explicit [] detaches every existing reference.
            $references[$column] = $columnRefs;
        }

        return new ResolvedFileReferenceInput($remainingBody, $references, $violations);
    }

    /**
     * @return list<mixed>|null null when the value cannot be read as file input at all
     */
    private function normalizeItems(mixed $value): ?array
    {
        if ($value === null) {
            return [];
        }

        if (\is_array($value)) {
            return array_is_list($value) ? $value : [$value];
        }

        return MathUtility::canBeInterpretedAsInteger($value) ? [$value] : null;
    }

    private function fileUid(mixed $item): ?int
    {
        if (\is_array($item)) {
            $item = $item['fileUid'] ?? null;
        }

        return MathUtility::canBeInterpretedAsInteger($item) ? (int)$item : null;
    }

    /** @return array<string, mixed> */
    private function referenceOverrides(mixed $item): array
    {
        if (!\is_array($item)) {
            return [];
        }

        return array_intersect_key($item, array_flip(self::WRITABLE_REFERENCE_FIELDS));
    }

    private function fileExists(int $fileUid): bool
    {
        $found = $this->connectionPool
            ->getConnectionForTable('sys_file')
            ->select(['uid'], 'sys_file', ['uid' => $fileUid])
            ->fetchAssociative();

        return $found !== false;
    }

    /** @return array{propertyPath: string, message: string, code: string} */
    private function violation(string $column, int|string|null $index, string $message, string $code): array
    {
        $path = $index !== null ? $column . '.' . $index : $column;

        return [
            'propertyPath' => $path,
            'message'      => sprintf("Field '%s' %s", $path, $message),
            'code'         => $code,
        ];
    }
}
