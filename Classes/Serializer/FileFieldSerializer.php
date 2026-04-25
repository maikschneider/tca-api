<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Serializer;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessorInterface;
use MaikSchneider\TcaApi\Serializer\FileProcessing\ImageProcessor;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Schema\Field\FileFieldType;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Serializes type=file TCA columns to their JSON-LD representation.
 *
 * Resolves sys_file_reference records via FileRepository and processes
 * each reference through the configured FileProcessorInterface (defaulting
 * to ImageProcessor). Single-file fields (maxitems=1) return a scalar
 * value; multi-file fields return an array.
 */
final class FileFieldSerializer
{
    public function __construct(
        private readonly FileRepository $fileRepository,
    ) {
    }

    public function serialize(string $column, FileFieldType $field, ColumnDefinition $columnDef, string $table, int $uid): mixed
    {
        $processor = $this->resolveProcessor($columnDef);
        $fileRefs  = $this->fileRepository->findByRelation($table, $column, $uid);

        if (($field->getConfiguration()['maxitems'] ?? 0) === 1) {
            return isset($fileRefs[0]) ? $processor->process($fileRefs[0], $columnDef) : null;
        }

        return array_map(fn ($ref) => $processor->process($ref, $columnDef), $fileRefs);
    }

    private function resolveProcessor(ColumnDefinition $columnDef): FileProcessorInterface
    {
        if ($columnDef->processor !== null) {
            /** @var class-string<FileProcessorInterface> $processorClass */
            $processorClass = $columnDef->processor;
            return GeneralUtility::makeInstance($processorClass);
        }
        return GeneralUtility::makeInstance(ImageProcessor::class);
    }
}
