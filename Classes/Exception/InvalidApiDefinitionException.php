<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Exception;

/**
 * Thrown at boot time by {@see \MaikSchneider\TcaApi\Loader\ApiDefinitionLoader}
 * when a registered resource exposes a TCA-level configuration that the
 * extension cannot satisfy at runtime.
 *
 * Extends {@see \InvalidArgumentException} so existing call sites that catch
 * the base type (e.g. ApiDefinition::fromArray validation) continue to behave
 * consistently — boot-time validation is one continuous error class.
 */
final class InvalidApiDefinitionException extends \InvalidArgumentException
{
}
