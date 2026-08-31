<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\DataAccess\PreloadedFileReferences;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessor;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessorInterface;
use MaikSchneider\TcaApi\Serializer\FileProcessing\ImageProcessor;
use MaikSchneider\TcaApi\Serializer\Processing\ProcessorGuard;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Schema\Field\FileFieldType;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Serializes type=file TCA columns to their JSON-LD representation.
 *
 * References come from the page-wide preload when the record is covered by it,
 * and from FileRepository otherwise — an embedded record, or a context the
 * preloader does not handle. Each reference is then run through a
 * FileProcessorInterface. The processor is chosen as
 * follows: an explicit 'processor' on the column definition wins; otherwise
 * the TCA 'allowed' extension list is compared against GFX/imagefile_ext —
 * all-image subsets use ImageProcessor, everything else uses FileProcessor.
 * Single-file fields (maxitems=1) return a scalar value; multi-file fields
 * return an array.
 */
final class FileFieldSerializer
{
    public function __construct(
        private readonly FileRepository $fileRepository,
        private readonly ProcessorGuard $processorGuard,
    ) {
    }

    public function serialize(
        string $column,
        FileFieldType $field,
        ColumnDefinition $columnDef,
        string $table,
        int $uid,
        ?PreloadedFileReferences $preloadedReferences = null,
    ): mixed {
        $processorClass = $this->resolveProcessorClass($columnDef, $field);
        $fileRefs       = $preloadedReferences?->find($column, $uid)
            ?? $this->fileRepository->findByRelation($table, $column, $uid);

        $isSingle = ($field->getConfiguration()['maxitems'] ?? 0) === 1;

        // Built once per column, not per reference: a processor that cannot be
        // constructed is a config error, and one log line for the column beats one
        // per file in a multi-file field across a whole collection.
        $processor = $this->processorGuard->instantiate($processorClass, $table, $column, $uid);
        if (!$processor instanceof FileProcessorInterface) {
            return $isSingle ? null : [];
        }

        $process = fn ($ref) => $this->processorGuard->run(
            static fn () => $processor->process($ref, $columnDef),
            $processorClass,
            $table,
            $column,
            $uid,
        );

        if ($isSingle) {
            return isset($fileRefs[0]) ? $process($fileRefs[0]) : null;
        }

        // A reference that fails processing is dropped rather than left as a null hole,
        // so the remaining files in a multi-file field still serialize normally.
        return array_values(array_filter(array_map($process, $fileRefs), static fn ($v) => $v !== null));
    }

    /** @return class-string<FileProcessorInterface> */
    private function resolveProcessorClass(ColumnDefinition $columnDef, FileFieldType $field): string
    {
        /** @var class-string<FileProcessorInterface> $processorClass */
        $processorClass = $columnDef->processor
            ?? $this->detectProcessorClass($field->getAllowedFileExtensions());

        return $processorClass;
    }

    private function detectProcessorClass(array $allowedExtensions): string
    {
        if ($allowedExtensions === []) {
            return FileProcessor::class;
        }

        $imageExts = GeneralUtility::trimExplode(
            ',',
            strtolower($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] ?? ''),
            true,
        );

        foreach ($allowedExtensions as $ext) {
            if ($ext === '*' || !\in_array(strtolower($ext), $imageExts, true)) {
                return FileProcessor::class;
            }
        }

        return ImageProcessor::class;
    }
}
