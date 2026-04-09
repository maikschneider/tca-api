<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\FileProcessing;

use TYPO3\CMS\Core\Resource\FileReference;

final class FileProcessor implements FileProcessorInterface
{
    public function process(FileReference $fileReference, array $columnConfig): array
    {
        return [
            'publicUrl' => $fileReference->getPublicUrl(),
            'mimeType'  => $fileReference->getMimeType(),
            'fileSize'  => $fileReference->getSize(),
            'metadata'  => [
                'title'       => $fileReference->getTitle() ?: null,
                'description' => $fileReference->getDescription() ?: null,
            ],
        ];
    }
}
