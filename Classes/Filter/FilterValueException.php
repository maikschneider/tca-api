<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Filter;

/**
 * Thrown when a filter value supplied by the client is not acceptable
 * (wrong shape, or more values than the filter allows).
 *
 * The dispatcher turns this into a 400 response and shows the message to the
 * client, so messages must never carry internal details.
 */
final class FilterValueException extends \RuntimeException
{
}
