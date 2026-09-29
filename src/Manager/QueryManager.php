<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the SQL written by the assistant on the connection set in the "connection" configuration of the bundle, meant for a MySQL user that can read the whole database but write nothing.
 * The checks made here keep out the tables and columns SchemaManager forbids, and keep away from the model the MySQL error messages that could quote a stored value.
 */
class QueryManager
{
    private const MAX_EXECUTION_TIME_MILLISECONDS = 10000;

    // MySQL errors whose message only quotes the query itself or names of the schema, the only ones passed on as they are. Any other message may quote a stored value (a truncated value, an
    // XPATH error built on purpose...), which must never reach the model: only its error code is passed on then.
    private const QUOTABLE_ERROR_CODES = [
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

    // Words after which a star is a column wildcard (SELECT *) rather than a multiplication
    private const STAR_WILDCARD_PREFIXES = ['select', 'distinct', 'distinctrow', 'all', 'high_priority', 'straight_join', 'sql_small_result', 'sql_big_result', 'sql_buffer_result', 'sql_no_cache', 'sql_calc_found_rows'];

    public function __construct(
        #[Autowire(service: 'tknoweb_ai_sql_assistant.query_connection')]
        private readonly Connection $queryConnection,
        private readonly SchemaManager $schemaManager,
    ) {
    }

    /**
     * Run a single SELECT statement and return its column names, at most $maxRows rows, and whether rows were left out beyond that limit.
     * The column names come from the first row, so they are empty when the query returns nothing.
     */
    public function execute(string $sql, int $maxRows): array
    {
        $sql = $this->getValidatedSql($sql);

        // Both settings only last for the session: the time limit applies to SELECT statements, and the read only mode would refuse a write even if a grant were added to the user by mistake
        $this->queryConnection->executeStatement('SET SESSION max_execution_time = '.self::MAX_EXECUTION_TIME_MILLISECONDS);
        $this->queryConnection->executeStatement('SET SESSION TRANSACTION READ ONLY');

        try {
            $result = $this->queryConnection->executeQuery($sql);
        } catch (DriverException $exception) {
            throw new \InvalidArgumentException($this->getSafeErrorMessage($exception), previous: $exception);
        }

        $rows = [];
        $truncated = false;
        while (false !== ($row = $result->fetchAssociative())) {
            if (count($rows) === $maxRows) {
                $truncated = true;
                break;
            }

            $rows[] = $row;
        }

        $result->free();

        return [
            'columns' => array_keys($rows[0] ?? []),
            'rows' => $rows,
            'truncated' => $truncated,
        ];
    }

    /**
     * Strip the trailing semicolon, then refuse anything else than a single SELECT statement, possibly introduced by a WITH clause, and any query that could read a forbidden table or column.
     * A column can only be read by naming it or through a star: a query is therefore refused when it names a forbidden table or column anywhere, even in a string or a comment, and when it holds
     * a column wildcard (SELECT *, t.*), COUNT(*) apart. A semicolon inside a string literal is refused as well, which is an acceptable limitation for statistics queries.
     */
    private function getValidatedSql(string $sql): string
    {
        $sql = rtrim(trim($sql), '; ');

        if (!preg_match('/^(SELECT|WITH)\b/i', $sql)) {
            throw new \InvalidArgumentException('Only a SELECT statement is allowed, optionally introduced by a WITH clause.');
        }

        if (str_contains($sql, ';')) {
            throw new \InvalidArgumentException('Only a single statement is allowed, the query must not contain any semicolon.');
        }

        // MySQL runs the content of a /*! ... */ comment, which would hide SQL from the checks below
        if (str_contains($sql, '/*!')) {
            throw new \InvalidArgumentException('Executable comments (/*! ... */) are not allowed.');
        }

        $forbiddenNames = array_map(fn (string $name) => preg_quote($name, '/'), $this->schemaManager->getForbiddenNames());
        if (preg_match('/(?<![\w$])('.implode('|', $forbiddenNames).')(?![\w$])/i', $sql, $matches)) {
            throw new \InvalidArgumentException(sprintf('"%s" is a forbidden table or column: the query must not mention it.', $matches[1]));
        }

        $code = preg_replace('/\bcount\s*\(\s*\*\s*\)/i', 'count(1)', $this->getCodeOnly($sql));
        if (preg_match('/(^|[(,.]|\b('.implode('|', self::STAR_WILDCARD_PREFIXES).'))\s*\*/i', $code)) {
            throw new \InvalidArgumentException('Column wildcards (SELECT *, t.*) are not allowed: name every selected column. COUNT(*) is allowed.');
        }

        // The TABLE statement reads every column of a table without naming them, like a star
        if (preg_match('/(?<![\w$])(table|into)(?![\w$])/i', $code, $matches)) {
            throw new \InvalidArgumentException(sprintf('The %s keyword is not allowed.', strtoupper($matches[1])));
        }

        return $sql;
    }

    /**
     * The query without its string literals, quoted identifiers and comments, which could hold a star or a keyword that is not one.
     */
    private function getCodeOnly(string $sql): string
    {
        return preg_replace(
            [
                "/'(?:[^'\\\\]|\\\\.|'')*'/s",
                '/"(?:[^"\\\\]|\\\\.|"")*"/s',
                '/`(?:[^`]|``)*`/s',
                '/\/\*.*?\*\//s',
                '/(--\s|#).*$/m',
            ],
            ["''", "''", 'x', ' ', ' '],
            $sql
        );
    }

    /**
     * Message of a failed query, as MySQL wrote it when that error only quotes the query or the schema, reduced to the error code otherwise.
     */
    private function getSafeErrorMessage(DriverException $exception): string
    {
        if (in_array($exception->getCode(), self::QUOTABLE_ERROR_CODES, true)) {
            return $exception->getMessage();
        }

        return sprintf('MySQL error %d (SQLSTATE %s). Its message is hidden, since it may quote a stored value.', $exception->getCode(), $exception->getSQLState() ?? 'unknown');
    }
}
