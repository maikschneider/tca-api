<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\Fixtures;

/**
 * Test-only virtual property callable.
 * Used in VirtualPropertiesTest to verify virtualProperties config.
 */
final class TestDisplayNameCallable
{
    public function displayName(array $serializedRow, array $rawRow): string
    {
        $lastName  = $rawRow['last_name'] ?? '';
        $firstName = $rawRow['first_name'] ?? '';

        return $lastName . ', ' . $firstName;
    }
}
