<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\DataAccess\DataWriteService;
use MaikSchneider\TcaApi\DataAccess\FileUploadService;
use MaikSchneider\TcaApi\Security\WriteContext;
use MaikSchneider\TcaApi\Validation\UploadValidator;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Shared file upload logic for CreateHandler and UpdateHandler.
 *
 * Stream-to-disk conversion is centralised here via ensureFileBacked().
 * Both the validator and the FAL service require a real file path — TYPO3's
 * built-in MimeTypeValidator reads file content via finfo, and ResourceStorage
 * explicitly rejects stream-backed UploadedFile objects.
 *
 * @property DataWriteService $writeService
 * @property FileUploadService $fileUploadService
 * @property UploadValidator $uploadValidator
 */
trait FileUploadTrait
{
    /**
     * Validate all uploaded files against their column upload constraints.
     *
     * @param array<string, UploadedFile|list<UploadedFile>> $uploadedFiles
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    private function validateUploads(array $uploadedFiles, ApiDefinition $config): array
    {
        $violations = [];

        foreach ($uploadedFiles as $column => $fileOrFiles) {
            $columnDef = $config->getColumn($column);
            if ($columnDef === null || $columnDef->upload === null) {
                continue;
            }

            $files = \is_array($fileOrFiles) ? $fileOrFiles : [$fileOrFiles];
            foreach ($files as $file) {
                $tmpPath = null;
                try {
                    [$backed, $tmpPath] = $this->ensureFileBacked($file);
                    array_push(
                        $violations,
                        ...$this->uploadValidator->validate($backed, $columnDef->upload, $config->table, $column),
                    );
                } finally {
                    if ($tmpPath !== null && file_exists($tmpPath)) {
                        unlink($tmpPath);
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Save uploaded files to FAL.
     *
     * Returns a nested map: column → [refKey => sys_file_reference data array].
     * uid_foreign is intentionally absent — DataHandler sets it when processing
     * the inline attachment in attachFileReferences().
     *
     * @param array<string, UploadedFile|list<UploadedFile>> $uploadedFiles
     * @return array<string, array<string, array>> column → [refKey → refData]
     */
    private function storeUploadedFiles(array $uploadedFiles, ApiDefinition $config): array
    {
        $stored = [];

        foreach ($uploadedFiles as $column => $fileOrFiles) {
            $columnDef = $config->getColumn($column);
            if ($columnDef === null || $columnDef->upload === null) {
                continue;
            }

            $files = \is_array($fileOrFiles) ? $fileOrFiles : [$fileOrFiles];

            foreach ($files as $file) {
                if ($file->getError() !== \UPLOAD_ERR_OK) {
                    continue;
                }

                $tmpPath = null;
                try {
                    [$backed, $tmpPath] = $this->ensureFileBacked($file);
                    $filename = $backed->getClientFilename() ?? 'upload';
                    $falFile  = $this->fileUploadService->store($backed, $columnDef->upload, $filename);
                } finally {
                    if ($tmpPath !== null && file_exists($tmpPath)) {
                        unlink($tmpPath);
                    }
                }

                $refKey = StringUtility::getUniqueId('NEW_ref');
                $stored[$column][$refKey] = [
                    'uid_local'   => $falFile->getUid(),
                    'tablenames'  => $config->table,
                    'fieldname'   => $column,
                    'table_local' => 'sys_file',
                    'hidden'      => 0,
                    'pid'         => $config->storagePid ?? 0,
                ];
            }
        }

        return $stored;
    }

    /**
     * Attach stored FAL files to the parent record.
     *
     * uid_foreign is set explicitly on each sys_file_reference entry so DataHandler
     * can write the correct parent link regardless of inline-relation remapping.
     * The parent column receives the reference count so TYPO3 keeps it consistent.
     *
     * @param array<string, array<string, array>> $storedFiles column → [refKey → refData]
     * @param WriteContext                         $writeContext Same context as the parent write
     */
    private function attachFileReferences(
        array $storedFiles,
        ApiDefinition $config,
        int $uid,
        WriteContext $writeContext,
    ): void {
        $parentColumnValues = [];
        $refDataMap         = [];

        foreach ($storedFiles as $column => $refs) {
            $parentColumnValues[$column] = count($refs);
            foreach ($refs as $refKey => $refData) {
                $refData['uid_foreign'] = $uid;
                $refDataMap[$refKey]    = $refData;
            }
        }

        $dataMap = [
            $config->table       => [$uid => $parentColumnValues],
            'sys_file_reference' => $refDataMap,
        ];

        $this->writeService->processDataMap($dataMap, $writeContext);
    }

    /**
     * Delete existing sys_file_reference records for the given columns via DataHandler.
     *
     * Must be called before attachFileReferences() on update operations so that
     * uploading a replacement file replaces prior references instead of appending.
     * Not needed on create — there are no existing references to clear.
     *
     * @param array<string, array<string, array>> $storedFiles column → [refKey → refData]
     * @param WriteContext                         $writeContext Same context as the parent write
     */
    private function deleteExistingFileReferences(
        array $storedFiles,
        ApiDefinition $config,
        int $uid,
        WriteContext $writeContext,
    ): void {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference');

        foreach (array_keys($storedFiles) as $column) {
            $existingUids = $connection->select(
                ['uid'],
                'sys_file_reference',
                [
                    'uid_foreign' => $uid,
                    'tablenames'  => $config->table,
                    'fieldname'   => $column,
                    'deleted'     => 0,
                ],
            )->fetchFirstColumn();

            foreach ($existingUids as $refUid) {
                $this->writeService->delete('sys_file_reference', (int)$refUid, $writeContext);
            }
        }
    }

    /**
     * Ensure an UploadedFile is backed by a real file path on disk.
     *
     * TYPO3's MimeTypeValidator and ResourceStorage both require
     * getTemporaryFileName() to return a non-null path. When the UploadedFile
     * is backed only by a PHP stream (e.g. in tests), the stream content is
     * written to a temporary file first.
     *
     * Returns a tuple [$file, $tmpPath]:
     *   - $file    — the (possibly new) UploadedFile with a real path
     *   - $tmpPath — the path of the created temp file, or null if no temp
     *                file was created (caller must unlink if non-null)
     *
     * @return array{0: UploadedFile, 1: string|null}
     */
    private function ensureFileBacked(UploadedFile $file): array
    {
        if ($file->getTemporaryFileName() !== null) {
            return [$file, null];
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'tca_api_upload_');
        $stream  = $file->getStream();
        $stream->rewind();
        file_put_contents($tmpPath, (string)$stream);

        $backed = new UploadedFile(
            $tmpPath,
            $file->getSize(),
            $file->getError(),
            $file->getClientFilename(),
            $file->getClientMediaType(),
        );

        return [$backed, $tmpPath];
    }
}
