<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\LinkDefinition;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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
 *
 * Linking is opt-in per column via the `link` config: the client names the file
 * by uid, and uids are enumerable, so without a declared scope an authenticated
 * caller could attach any file in the installation to their own record and read
 * its name and path back out of the response.
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

    public function resolve(array $body, ApiDefinition $config, ?ServerRequestInterface $request = null): ResolvedFileReferenceInput
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

            $link = $config->getColumn($column)?->link;
            if ($link === null) {
                $violations[] = $this->violation($column, null, 'does not accept links to existing files. Declare a "link" scope on the column to allow it.', 'LINKING_DISABLED');
                continue;
            }

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
                $file = $this->findFile($fileUid);
                if ($file === null) {
                    $violations[] = $this->violation($column, $index, sprintf('references file %d, which does not exist.', $fileUid), 'FILE_NOT_FOUND');
                    continue;
                }
                if (!$this->isLinkable($file, $link, $request)) {
                    $violations[] = $this->violation($column, $index, sprintf('references file %d, which is outside the folders this column may link.', $fileUid), 'FILE_NOT_LINKABLE');
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

        return $this->isReadableAsUid($value) ? [$value] : null;
    }

    private function fileUid(mixed $item): ?int
    {
        if (\is_array($item)) {
            $item = $item['fileUid'] ?? null;
        }

        return $this->isReadableAsUid($item) ? (int)$item : null;
    }

    /**
     * Booleans are filtered before the numeric test: canBeInterpretedAsInteger()
     * accepts `true` and would turn it into a link to file 1.
     */
    private function isReadableAsUid(mixed $value): bool
    {
        return !\is_bool($value) && MathUtility::canBeInterpretedAsInteger($value);
    }

    /** @return array<string, mixed> */
    private function referenceOverrides(mixed $item): array
    {
        if (!\is_array($item)) {
            return [];
        }

        return array_intersect_key($item, array_flip(self::WRITABLE_REFERENCE_FIELDS));
    }

    /**
     * The whole row, not just what the folder gate needs: link.check is handed
     * this array and a policy will reach for name, mime_type or an own column.
     *
     * @return array<string, mixed>|null
     */
    private function findFile(int $fileUid): ?array
    {
        $found = $this->connectionPool
            ->getConnectionForTable('sys_file')
            ->select(['*'], 'sys_file', ['uid' => $fileUid])
            ->fetchAssociative();

        return $found === false ? null : $found;
    }

    /**
     * Both constraints must hold when both are configured, so a custom check can
     * narrow the declared folders but never widen them.
     *
     * @param array<string, mixed> $file
     */
    private function isLinkable(array $file, LinkDefinition $link, ?ServerRequestInterface $request): bool
    {
        if (!$link->coversFolder((int)$file['storage'], (string)$file['identifier'])) {
            return false;
        }

        if ($link->check === null) {
            return true;
        }

        [$class, $method] = $link->check;

        return (bool)GeneralUtility::makeInstance($class)->$method($file, $request);
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
