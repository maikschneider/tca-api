<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Exception;

/**
 * Thrown by {@see \MaikSchneider\TcaApi\DataAccess\ResourceDataProvider} when a
 * resource name is requested that is not registered in the {@see \MaikSchneider\TcaApi\Registry\ApiRegistry}.
 *
 * Extends {@see \InvalidArgumentException} so non-HTTP callers (e.g. the Fluid
 * data layer) can catch the base type for a misconfigured resource name.
 */
final class UnknownResourceException extends \InvalidArgumentException
{
    public static function forResource(string $resourceName): self
    {
        return new self(sprintf('No TCA API resource registered for name "%s".', $resourceName));
    }
}
