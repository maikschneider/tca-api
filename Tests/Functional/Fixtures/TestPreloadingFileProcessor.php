<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Fixtures;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Serializer\FileProcessing\FileProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\PreloadingProcessorInterface;
use TYPO3\CMS\Core\Resource\FileReference;

/**
 * File processor for testing that the preload hook is not offered to columns the
 * file branch owns — those never reach a column processor.
 */
final class TestPreloadingFileProcessor implements FileProcessorInterface, ColumnProcessorInterface, PreloadingProcessorInterface
{
    public static int $prepareCalls = 0;

    public static function reset(): void
    {
        self::$prepareCalls = 0;
    }

    public function prepare(array $rows, ApiDefinition $config): void
    {
        ++self::$prepareCalls;
    }

    public function process(mixed $value, ColumnDefinition $config, array $context = []): array
    {
        return $value instanceof FileReference
            ? ['name' => $value->getOriginalFile()->getName()]
            : [];
    }
}
