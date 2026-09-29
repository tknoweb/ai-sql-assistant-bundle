<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Lets SQLite stand in for MySQL in the tests: the MySQL session statements ("SET SESSION ...") are recorded instead of being run, every other statement runs as it is.
 */
class MysqlSessionStatementMiddleware implements Middleware
{
    public static array $skippedStatements = [];

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): Connection {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function exec(string $sql): int
                    {
                        if (preg_match('/^\s*SET\s+SESSION\b/i', $sql)) {
                            MysqlSessionStatementMiddleware::$skippedStatements[] = $sql;

                            return 0;
                        }

                        return (int) parent::exec($sql);
                    }
                };
            }
        };
    }
}
