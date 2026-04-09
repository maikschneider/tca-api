<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\FileProcessing;

use TYPO3\CMS\Core\Resource\FileReference;

interface FileProcessorInterface
{
    /**
     * Process a FileReference into a serializable array.
     *
     * @param array $columnConfig  The TcaApi column config (may contain maxWidth, maxHeight, etc.)
     */
    public function process(FileReference $fileReference, array $columnConfig): array;
}
