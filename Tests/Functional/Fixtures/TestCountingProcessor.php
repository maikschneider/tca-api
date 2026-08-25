<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Fixtures;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;

/**
 * Processor for testing — counts how often it was invoked.
 */
final class TestCountingProcessor implements ColumnProcessorInterface
{
    public static int $invocations = 0;

    public static function reset(): void
    {
        self::$invocations = 0;
    }

    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        ++self::$invocations;

        return 'counted-value';
    }
}
