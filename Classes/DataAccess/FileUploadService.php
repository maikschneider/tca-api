<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\DataAccess;

use MaikSchneider\TcaApi\Configuration\UploadDefinition;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Resource\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stores a PSR-7 uploaded file into a TYPO3 FAL storage.
 *
 * Returns the created sys_file object whose uid can be referenced in a
 * sys_file_reference DataHandler entry.
 */
#[Autoconfigure(public: true)]
final class FileUploadService
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
    ) {
    }

    /**
     * Store an uploaded file in the FAL folder defined by $upload.
     *
     * @param UploadedFileInterface $file     PSR-7 uploaded file (already validated)
     * @param UploadDefinition      $upload   Column upload constraints (folder, duplication)
     * @param string                $filename Sanitised original filename to use in FAL
     * @return File The created sys_file record
     */
    public function store(
        UploadedFileInterface $file,
        UploadDefinition $upload,
        string $filename,
    ): File {
        [$storageUid, $folderPath] = explode(':/', $upload->folder, 2);

        $storage = $this->resourceFactory->getStorageObject((int)$storageUid);
        $folder  = $storage->hasFolder($folderPath)
            ? $storage->getFolder($folderPath)
            : $storage->createFolder($folderPath);

        $tmpPath = $this->resolveTemporaryPath($file);

        $behavior = match ($upload->duplication) {
            'replace' => DuplicationBehavior::REPLACE,
            'cancel'  => DuplicationBehavior::CANCEL,
            default   => DuplicationBehavior::RENAME,
        };

        return $storage->addFile($tmpPath, $folder, $filename, $behavior);
    }

    /**
     * Resolve the physical path of the uploaded file's temporary storage.
     *
     * PSR-7 implementations backed by a real PHP upload will expose the tmp
     * path via stream metadata. When that is unavailable (e.g. in-memory
     * streams during testing), the content is written to a temp file instead.
     */
    private function resolveTemporaryPath(UploadedFileInterface $file): string
    {
        $stream = $file->getStream();
        $uri    = $stream->getMetadata('uri');

        if (\is_string($uri) && $uri !== '' && file_exists($uri)) {
            return $uri;
        }

        // Fallback: write stream content to a temp file
        $tmpPath = GeneralUtility::tempnam('tcaapi_upload_');
        $stream->rewind();
        file_put_contents($tmpPath, (string)$stream);

        return $tmpPath;
    }
}
