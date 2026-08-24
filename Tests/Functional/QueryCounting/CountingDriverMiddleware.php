<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Tests\Functional\QueryCounting;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Doctrine driver middleware that feeds {@see QueryCounter}.
 *
 * Registered through TYPO3_CONF_VARS/DB/Connections/Default/driverMiddlewares
 * in tests that assert a request does not scale its query count with the
 * number of returned records.
 */
final class CountingDriverMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): DriverConnection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        return new class(parent::prepare($sql)) extends AbstractStatementMiddleware {
                            public function execute(): Result
                            {
                                QueryCounter::record();

                                return parent::execute();
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        QueryCounter::record();

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        QueryCounter::record();

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
