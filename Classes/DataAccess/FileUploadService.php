<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final readonly class FileUploadService
{
    public function __construct(
        private ResourceFactory $resourceFactory,
    ) {
    }

    public function upload(
        UploadedFileInterface $uploadedFile,
        ?int $storageUid = null,
        ?string $targetFolderIdentifier = null,
    ): FileInterface {
        $storage = $storageUid !== null
            ? $this->resourceFactory->getStorageObject($storageUid)
            : $this->resourceFactory->getDefaultStorage();

        if (!$storage->isOnline()) {
            throw new \RuntimeException('Configured storage is offline');
        }

        $targetFolder = $targetFolderIdentifier !== null && $targetFolderIdentifier !== ''
            ? $storage->getFolder($targetFolderIdentifier)
            : $storage->getDefaultFolder();

        $targetFilename = $this->sanitizeFilename((string)($uploadedFile->getClientFilename() ?? 'upload.bin'));

        return $storage->addUploadedFile($uploadedFile, $targetFolder, $targetFilename);
    }

    private function sanitizeFilename(string $filename): string
    {
        $basename = basename(str_replace("\0", '', $filename));
        $basename = trim($basename);
        $basename = (string)(preg_replace('/[^\pL\pN._-]+/u', '_', $basename) ?? '');
        $basename = ltrim($basename, '.');

        if ($basename === '' || $basename === '.' || $basename === '..') {
            return 'upload.bin';
        }

        return $basename;
    }
}
