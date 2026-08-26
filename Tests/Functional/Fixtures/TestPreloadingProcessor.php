<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Fixtures;

use MaikSchneider\TcaApi\Configuration\ApiDefinition;
use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;
use MaikSchneider\TcaApi\Serializer\Processing\PreloadingProcessorInterface;

/**
 * Processor for testing the preload hook — records how often prepare() ran, with
 * how many rows, and serves process() from what prepare() collected.
 */
final class TestPreloadingProcessor implements ColumnProcessorInterface, PreloadingProcessorInterface
{
    public static int $prepareCalls = 0;

    /** @var list<int> */
    public static array $preparedRowCounts = [];

    /** @var array<int, string> */
    private array $batch = [];

    public static function reset(): void
    {
        self::$prepareCalls = 0;
        self::$preparedRowCounts = [];
    }

    public function prepare(array $rows, ApiDefinition $config): void
    {
        ++self::$prepareCalls;
        self::$preparedRowCounts[] = \count($rows);

        foreach ($rows as $row) {
            $this->batch[(int)$row['uid']] = 'batched-' . $row['uid'];
        }
    }

    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        $uid = (int)($context['rawRow']['uid'] ?? 0);

        return $this->batch[$uid] ?? 'unbatched';
    }
}
