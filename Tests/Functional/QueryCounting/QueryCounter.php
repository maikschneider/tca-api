<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\QueryCounting;

/**
 * Process-wide counter for statements issued through the Doctrine driver.
 *
 * Functional tests execute the frontend sub-request in the same PHP process as
 * the test case, so a static counter is enough to attribute queries to a request.
 */
final class QueryCounter
{
    private static int $count = 0;

    private static bool $enabled = false;

    public static function start(): void
    {
        self::$count   = 0;
        self::$enabled = true;
    }

    public static function stop(): int
    {
        self::$enabled = false;

        return self::$count;
    }

    public static function record(): void
    {
        if (self::$enabled) {
            ++self::$count;
        }
    }
}
