<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\OperationHandler;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Security\WriteContext;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Shared file upload logic for CreateHandler and UpdateHandler.
 *
 * Requires the using class to declare:
 *   - DataWriteService     $writeService
 *   - FileUploadService    $fileUploadService
 *   - UploadValidator      $uploadValidator
 */
trait FileUploadTrait
{
    /**
     * Validate all uploaded files against their column upload constraints.
     *
     * @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $uploadedFiles
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
                if (!$file instanceof UploadedFileInterface) {
                    continue;
                }
                array_push(
                    $violations,
                    ...$this->uploadValidator->validate($file, $columnDef->upload, $column),
                );
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
     * @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $uploadedFiles
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
                if (!$file instanceof UploadedFileInterface || $file->getError() !== \UPLOAD_ERR_OK) {
                    continue;
                }

                $filename = $file->getClientFilename() ?? 'upload';
                $falFile  = $this->fileUploadService->store($file, $columnDef->upload, $filename);
                $refKey   = StringUtility::getUniqueId('NEW_ref');

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
}
