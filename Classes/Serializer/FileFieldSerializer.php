<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
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
 * Resolves sys_file_reference records via FileRepository and processes
 * each reference through a FileProcessorInterface. The processor is chosen as
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

    public function serialize(string $column, FileFieldType $field, ColumnDefinition $columnDef, string $table, int $uid): mixed
    {
        $processor = $this->resolveProcessor($columnDef, $field);
        $fileRefs  = $this->fileRepository->findByRelation($table, $column, $uid);

        $process = fn ($ref) => $this->processorGuard->run(
            fn () => $processor->process($ref, $columnDef),
            $processor::class,
            $table,
            $column,
            $uid,
        );

        if (($field->getConfiguration()['maxitems'] ?? 0) === 1) {
            return isset($fileRefs[0]) ? $process($fileRefs[0]) : null;
        }

        // A reference that fails processing is dropped rather than left as a null hole,
        // so the remaining files in a multi-file field still serialize normally.
        return array_values(array_filter(array_map($process, $fileRefs), static fn ($v) => $v !== null));
    }

    private function resolveProcessor(ColumnDefinition $columnDef, FileFieldType $field): FileProcessorInterface
    {
        if ($columnDef->processor !== null) {
            /** @var class-string<FileProcessorInterface> $processorClass */
            $processorClass = $columnDef->processor;
            return GeneralUtility::makeInstance($processorClass);
        }

        /** @var class-string<FileProcessorInterface> $processorClass */
        $processorClass = $this->detectProcessorClass($field->getAllowedFileExtensions());
        return GeneralUtility::makeInstance($processorClass);
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
