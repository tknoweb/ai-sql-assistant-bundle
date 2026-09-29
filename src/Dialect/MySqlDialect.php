<?php

namespace Tknoweb\AiSqlAssistantBundle\Dialect;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * MySQL 8: a read only session with a time limit on SELECT statements, and a flattening that swaps its tables in a single RENAME TABLE.
 */
class MySqlDialect extends SqlDialect
{
    private const OLD_TABLE_SUFFIX = '_old';

    // Errors whose message only quotes the query itself or names of the schema, the only ones passed on as they are. Any other message may quote a stored value (a truncated value, an
    // XPATH error built on purpose...), which must never reach the model: only its error code is passed on then.
    protected const QUOTABLE_ERROR_CODES = [
        1052, // ambiguous column
        1054, // unknown column
        1055, // column missing from the GROUP BY
        1060, // duplicate column name
        1064, // syntax error, quoting the query near the error
        1066, // table or alias used twice
        1109, // unknown table
        1111, // invalid use of a group function
        1142, // SELECT denied on a table
        1143, // SELECT denied on a column
        1146, // table does not exist
        1221, // incorrect use of UNION and ORDER BY
        1222, // different number of columns in a UNION
        1235, // feature not supported
        1241, // operand should contain n columns
        1242, // subquery returning more than one row
        1248, // derived table without an alias
        1267, // illegal mix of collations
        1270, // illegal mix of collations
        1271, // illegal mix of collations
        1305, // function does not exist
        1582, // incorrect parameter count
        1583, // incorrect parameters
        1630, // function does not exist, space before the parenthesis
        3024, // maximum execution time exceeded
        3065, // ORDER BY column missing from a SELECT DISTINCT
        3141, // invalid JSON text, the message giving a position but not the text
        3143, // invalid JSON path, quoting the path written in the query
    ];

    public function getName(): string
    {
        return 'MySQL 8';
    }

    protected function getEngineName(): string
    {
        return 'MySQL';
    }

    /**
     * Both settings only last for the session: the time limit applies to SELECT statements, and the read only mode would refuse a write even if a grant were added to the user by mistake.
     */
    public function prepareSession(Connection $connection, int $timeLimitMilliseconds): void
    {
        $connection->executeStatement('SET SESSION max_execution_time = '.$timeLimitMilliseconds);
        $connection->executeStatement('SET SESSION TRANSACTION READ ONLY');
    }

    public function isQuotableError(DriverException $exception): bool
    {
        return in_array($exception->getCode(), static::QUOTABLE_ERROR_CODES, true);
    }

    public function getForbiddenFunctionPattern(): ?string
    {
        return '/^(load_file|sys_exec|sys_eval)$/i';
    }

    protected function getQuotes(): array
    {
        return ["'" => "'", '"' => '"', '`' => '`'];
    }

    protected function hasHashComments(): bool
    {
        return true;
    }

    /**
     * MySQL only reads "--" as a comment when a space or a control character follows it: "1--1" is a subtraction.
     */
    protected function isDoubleDashComment(string $sql, int $offset): bool
    {
        return !isset($sql[$offset + 2]) || ord($sql[$offset + 2]) <= 0x20;
    }

    /**
     * A complete copy of the table, indexes and identity included, since it becomes the live table at the swap.
     */
    public function createCopy(Connection $connection, string $table, string $copy, array $columns): void
    {
        $platform = $connection->getDatabasePlatform();
        $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::quoteName($platform, $copy)));
        $connection->executeStatement(sprintf('CREATE TABLE %s LIKE %s', self::quoteName($platform, $copy), self::quoteName($platform, $table)));
    }

    /**
     * Every table and its copy swapped at once by a single RENAME TABLE, which MySQL runs atomically, then the previous tables dropped.
     */
    public function swapCopies(Connection $connection, array $copies, array $columns): void
    {
        $platform = $connection->getDatabasePlatform();
        $renames = [];
        foreach ($copies as $table => $copy) {
            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::quoteName($platform, $table.self::OLD_TABLE_SUFFIX)));
            $renames[] = sprintf('%1$s TO %2$s, %3$s TO %1$s', self::quoteName($platform, $table), self::quoteName($platform, $table.self::OLD_TABLE_SUFFIX), self::quoteName($platform, $copy));
        }

        $connection->executeStatement('RENAME TABLE '.implode(', ', $renames));

        foreach (array_keys($copies) as $table) {
            $connection->executeStatement(sprintf('DROP TABLE %s', self::quoteName($platform, $table.self::OLD_TABLE_SUFFIX)));
        }
    }
}
