<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\FileProcessing;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use TYPO3\CMS\Core\Resource\FileReference;

/**
 * Serializes a FAL FileReference into the default file shape.
 *
 * `uid`, `name` and `extension` describe the underlying sys_file, not the
 * reference: they identify the file itself, which is what a client needs to
 * recognise it across responses, match it against a listing, or render a
 * filename and a type badge.
 */
final class FileProcessor implements FileProcessorInterface
{
    public function process(FileReference $fileReference, ColumnDefinition $columnConfig): array
    {
        $originalFile = $fileReference->getOriginalFile();

        return [
            'uid'       => $originalFile->getUid(),
            'name'      => $originalFile->getName(),
            'extension' => $originalFile->getExtension(),
            'publicUrl' => UrlNormalizer::toRootRelative($fileReference->getPublicUrl()),
            'mimeType'  => $fileReference->getMimeType(),
            'fileSize'  => $fileReference->getSize(),
            'metadata'  => [
                'title'       => $fileReference->getTitle() ?: null,
                'description' => $fileReference->getDescription() ?: null,
            ],
        ];
    }
}
