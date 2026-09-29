<?php

namespace Tknoweb\AiSqlAssistantBundle\Dialect;

use Doctrine\DBAL\Connection;

/**
 * MariaDB, read as MySQL but for the time limit of its session, which MariaDB sets in seconds on every statement.
 */
class MariaDbDialect extends MySqlDialect
{
    protected const QUOTABLE_ERROR_CODES = [
        ...parent::QUOTABLE_ERROR_CODES,
        1969, // maximum statement time exceeded
    ];

    public function getName(): string
    {
        return 'MariaDB';
    }

    protected function getEngineName(): string
    {
        return 'MariaDB';
    }

    public function prepareSession(Connection $connection, int $timeLimitMilliseconds): void
    {
        $connection->executeStatement('SET SESSION max_statement_time = '.number_format($timeLimitMilliseconds / 1000, 3, '.', ''));
        $connection->executeStatement('SET SESSION TRANSACTION READ ONLY');
    }
}
