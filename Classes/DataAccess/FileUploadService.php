<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Configuration\UploadDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Stores a file-backed TYPO3 UploadedFile into a FAL storage.
 *
 * The caller (FileUploadTrait) is responsible for ensuring the UploadedFile
 * is backed by a real file path before calling store() — TYPO3 validators and
 * ResourceStorage both require getTemporaryFileName() to return a non-null path.
 * Use FileUploadTrait::ensureFileBacked() to convert stream-based files first.
 *
 * Returns the created sys_file object whose uid can be referenced in a
 * sys_file_reference DataHandler entry.
 */
#[Autoconfigure(public: true)]
final class FileUploadService
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
    ) {
    }

    /**
     * Store a file-backed UploadedFile in the FAL folder defined by $upload.
     *
     * @param UploadedFile     $file     TYPO3 UploadedFile backed by a real tmp path (already validated)
     * @param UploadDefinition $upload   Column upload constraints (folder, duplication)
     * @param string           $filename Sanitised original filename to use in FAL
     * @return File The created sys_file record
     */
    public function store(
        UploadedFile $file,
        UploadDefinition $upload,
        string $filename,
    ): File {
        [$storageUid, $folderPath] = explode(':/', $upload->folder, 2);

        $storage = $this->storageRepository->getStorageObject((int)$storageUid);
        $folder  = $storage->hasFolder($folderPath)
            ? $storage->getFolder($folderPath)
            : $storage->createFolder($folderPath);

        $behavior = match ($upload->duplication) {
            'replace' => DuplicationBehavior::REPLACE,
            'cancel'  => DuplicationBehavior::CANCEL,
            default   => DuplicationBehavior::RENAME,
        };

        // getTemporaryFileName() is guaranteed non-null by ensureFileBacked() in the trait.
        $tmpPath  = (string)$file->getTemporaryFileName();
        $filename = $upload->applyMask($filename, $tmpPath);

        return $storage->addFile(
            $tmpPath,
            $folder,
            $filename,
            $behavior,
        );
    }
}
