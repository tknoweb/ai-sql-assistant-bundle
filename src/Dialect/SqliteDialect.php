<?php

namespace Tknoweb\AiSqlAssistantBundle\Dialect;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * SQLite, mostly for tests and small applications: a connection in query only mode, but no time limit, SQLite offering none to PHP.
 */
class SqliteDialect extends SqlDialect
{
    // Start of the messages that only quote the query itself or names of the schema, SQLite giving the same error code to nearly every error
    private const QUOTABLE_ERROR_PATTERN = '/General error: \d+ (no such (table|column|function)|ambiguous column name|near ".*": syntax error|incomplete input|misuse of (aggregate|window function)|wrong number of arguments to function|SELECTs to the left and right of|\d+\w* ORDER BY term|a GROUP BY clause is required|aggregate functions are not allowed|attempt to write a readonly database)/';

    public function getName(): string
    {
        return 'SQLite';
    }

    protected function getEngineName(): string
    {
        return 'SQLite';
    }

    public function prepareSession(Connection $connection, int $timeLimitMilliseconds): void
    {
        $connection->executeStatement('PRAGMA query_only = ON');
    }

    public function isQuotableError(DriverException $exception): bool
    {
        return 1 === preg_match(self::QUOTABLE_ERROR_PATTERN, $exception->getMessage());
    }

    public function getForbiddenFunctionPattern(): ?string
    {
        return '/^(load_extension|fts3_tokenizer|readfile|writefile|edit)$/i';
    }

    protected function getQuotes(): array
    {
        return ["'" => "'", '"' => '"', '`' => '`', '[' => ']'];
    }

    /**
     * The default of SQLite since its version 3.32.
     */
    public function getMaxParameters(): int
    {
        return 32766;
    }

    /**
     * SQLite cannot replace a view: it is dropped and created again in a transaction, which no reader sees half done.
     */
    public function createOrReplaceView(Connection $connection, string $view, string $select): void
    {
        $platform = $connection->getDatabasePlatform();
        $connection->beginTransaction();
        try {
            $connection->executeStatement(sprintf('DROP VIEW IF EXISTS %s', self::quoteName($platform, $view)));
            $connection->executeStatement(sprintf('CREATE VIEW %s AS %s', self::quoteName($platform, $view), $select));
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }
}
