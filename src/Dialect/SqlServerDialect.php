<?php

namespace Tknoweb\AiSqlAssistantBundle\Dialect;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * SQL Server 2017 or later. It has no read only session: what keeps a query of the model from writing is the database user, granted nothing but db_datareader, the refusal of every statement
 * keyword (SQL Server runs a batch of several statements without any semicolon between them), and the transaction QueryManager rolls back after each query. The time limit of a query
 * needs the pdo_sqlsrv driver, whose query timeout applies to the whole connection, the waits for a lock being bounded whatever the driver.
 */
class SqlServerDialect extends SqlDialect
{
    // Error numbers whose message only quotes the query itself or names of the schema, the only ones passed on as they are. Any other message may quote a stored value (a failed conversion
    // of a string, for instance), which must never reach the model: only its error number is passed on then.
    private const QUOTABLE_ERROR_NUMBERS = [
        102, // syntax error, quoting the query near the error
        105, // unclosed quotation mark, quoting the query
        116, // subquery selecting several columns
        130, // aggregate of an aggregate or a subquery
        144, // aggregate or subquery in a GROUP BY
        145, // ORDER BY column missing from a SELECT DISTINCT
        147, // aggregate in a WHERE clause
        156, // syntax error near a keyword
        174, // wrong number of arguments of a function
        189, // wrong number of arguments of a function
        195, // unknown built-in function
        205, // different number of columns in a UNION
        206, // operand type clash, naming the types
        207, // invalid column name
        208, // invalid object name
        209, // ambiguous column name
        229, // permission denied on an object
        230, // permission denied on a column
        241, // conversion of a string to a date, without the string
        306, // text types compared or sorted
        402, // incompatible data types, naming the types
        512, // subquery returning more than one value
        1013, // table or alias used twice
        1033, // ORDER BY in a subquery without TOP or OFFSET
        1222, // lock wait timeout
        4104, // multi-part identifier that cannot be bound
        4108, // window function misplaced
        4121, // unknown column or function
        4145, // non boolean expression where a condition is expected, quoting the query
        8114, // conversion between two data types, naming the types
        8115, // arithmetic overflow, naming the types
        8116, // invalid argument type of a function
        8117, // invalid operand type of an operator
        8120, // column missing from the GROUP BY
        8121, // HAVING column missing from the GROUP BY
        8127, // ORDER BY column missing from the GROUP BY
        8134, // division by zero
        8155, // derived table column without a name
        8156, // column specified twice in a derived table
        10753, // window function without an OVER clause
    ];

    // SQLSTATE of the query timeout of the driver
    private const TIMEOUT_SQLSTATE = 'HYT00';

    // Keywords of the statements a batch could chain after the SELECT, all of which change something or wait
    private const BATCH_STATEMENT_KEYWORDS = ['set', 'use', 'declare', 'print', 'raiserror', 'throw', 'waitfor', 'kill', 'shutdown', 'backup', 'restore', 'dbcc', 'bulk', 'reconfigure', 'checkpoint', 'save', 'deny', 'while', 'goto', 'readtext', 'writetext', 'updatetext', 'enable', 'disable', 'revert', 'setuser', 'send', 'receive'];

    public function getName(): string
    {
        return 'SQL Server (Transact-SQL)';
    }

    protected function getEngineName(): string
    {
        return 'SQL Server';
    }

    public function prepareSession(Connection $connection, int $timeLimitMilliseconds): void
    {
        $connection->executeStatement('SET LOCK_TIMEOUT '.$timeLimitMilliseconds);

        $nativeConnection = $connection->getNativeConnection();
        if ($nativeConnection instanceof \PDO && defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            $nativeConnection->setAttribute(constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT'), (int) ceil($timeLimitMilliseconds / 1000));
        }
    }

    public function isQuotableError(DriverException $exception): bool
    {
        return in_array($exception->getCode(), self::QUOTABLE_ERROR_NUMBERS, true) || self::TIMEOUT_SQLSTATE === $exception->getSQLState();
    }

    public function getForbiddenKeywords(): array
    {
        return [...parent::getForbiddenKeywords(), ...self::BATCH_STATEMENT_KEYWORDS];
    }

    /**
     * Functions running a query on another server or reading a file, and the system procedures.
     */
    public function getForbiddenFunctionPattern(): ?string
    {
        return '/^(openquery|openrowset|opendatasource|xp_\w+|sp_\w+)$/i';
    }

    /**
     * The limit of SQL Server, whatever the driver.
     */
    public function getMaxParameters(): int
    {
        return 2100;
    }

    protected function getQuotes(): array
    {
        return ["'" => "'", '"' => '"', '[' => ']'];
    }

    /**
     * SQL Server has no CREATE TABLE ... AS SELECT: SELECT ... INTO creates the copy.
     */
    public function createCopy(Connection $connection, string $table, string $copy, array $columns): void
    {
        $platform = $connection->getDatabasePlatform();
        $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::quoteName($platform, $copy)));
        $connection->executeStatement(sprintf('SELECT %s INTO %s FROM %s WHERE 1 = 0', self::quoteNames($platform, $columns), self::quoteName($platform, $copy), self::quoteName($platform, $table)));
    }

    /**
     * SQL Server writes CREATE OR ALTER rather than CREATE OR REPLACE.
     */
    public function createOrReplaceView(Connection $connection, string $view, string $select): void
    {
        $connection->executeStatement(sprintf('CREATE OR ALTER VIEW %s AS %s', self::quoteName($connection->getDatabasePlatform(), $view), $select));
    }
}
