<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Fixtures;

use MaikSchneider\TcaApi\Serializer\Processing\ColumnProcessorInterface;

/**
 * Minimal processor for testing — returns a fixed string value.
 */
final class TestStaticValueProcessor implements ColumnProcessorInterface
{
    public function process(mixed $value, array $config, array $context): mixed
    {
        return 'static-value';
    }
}
