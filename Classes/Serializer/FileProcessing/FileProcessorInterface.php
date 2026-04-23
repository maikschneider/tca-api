<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer\FileProcessing;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use TYPO3\CMS\Core\Resource\FileReference;

interface FileProcessorInterface
{
    /**
     * Process a FileReference into a serializable array.
     */
    public function process(FileReference $fileReference, ColumnDefinition $columnConfig): array;
}
