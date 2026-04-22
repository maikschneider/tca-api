<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Fixtures;

use MaikSchneider\TcaApi\Configuration\ColumnDefinition;
use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;

/**
 * Test processor that echoes back the raw value passed to it.
 * Used to assert that the correct source value reaches the processor.
 */
final class TestEchoProcessor implements ColumnProcessorInterface
{
    public function process(mixed $value, ColumnDefinition $config, array $context): mixed
    {
        return $value;
    }
}
