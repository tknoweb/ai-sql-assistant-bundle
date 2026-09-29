<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tknoweb\AiSqlAssistantBundle\Dialect\SqlDialect;

/**
 * Runs the SQL written by the assistant on the connection set in the "connection" configuration of the bundle, meant for a database user that can read the whole database but write nothing.
 * The checks made here keep out the tables and columns SchemaManager forbids, and keep away from the model the error messages of the database that could quote a stored value. What differs
 * from one engine to another (reading the query, the settings of the session, the errors to pass on) comes from the SqlDialect of the connection.
 */
class QueryManager
{
    private const MAX_EXECUTION_TIME_MILLISECONDS = 10000;

    // Words after which a star is a column wildcard (SELECT *) rather than a multiplication, and words before which it can only be one (SELECT TOP 10 * FROM)
    private const STAR_WILDCARD_PREFIXES = ['select', 'distinct', 'distinctrow', 'all', 'high_priority', 'straight_join', 'sql_small_result', 'sql_big_result', 'sql_buffer_result', 'sql_no_cache', 'sql_calc_found_rows', 'percent', 'ties'];
    private const STAR_WILDCARD_SUFFIXES = ['from', 'into', 'where', 'group', 'having', 'order', 'limit', 'union', 'intersect', 'except', 'window', 'for'];
    private const COUNT_FUNCTIONS = ['count', 'count_big'];
    // The TABLE statement reads every column of a table without naming them, like a star, and INTO writes the result somewhere
    private const FORBIDDEN_KEYWORDS = ['table', 'into'];
    // Statement keywords that are also MySQL functions, INSERT(string, ...) and TRUNCATE(number, decimals), allowed when called since no statement puts a parenthesis right after them
    private const FUNCTION_KEYWORDS = ['insert', 'truncate'];

    private ?SqlDialect $dialect = null;

    public function __construct(
        #[Autowire(service: 'tknoweb_ai_sql_assistant.query_connection')]
        private readonly Connection $queryConnection,
        private readonly SchemaManager $schemaManager,
    ) {
    }

    /**
     * Dialect of the query connection, the SQL the model writes in.
     */
    public function getDialect(): SqlDialect
    {
        return $this->dialect ??= SqlDialect::fromPlatform($this->queryConnection->getDatabasePlatform());
    }

    /**
     * Run a single SELECT statement and return its column names, at most $maxRows rows, and whether rows were left out beyond that limit.
     * The column names come from the first row, so they are empty when the query returns nothing.
     */
    public function execute(string $sql, int $maxRows): array
    {
        $sql = $this->getValidatedSql($sql);
        $dialect = $this->getDialect();
        $dialect->prepareSession($this->queryConnection, self::MAX_EXECUTION_TIME_MILLISECONDS);

        // The query runs in a transaction that is always rolled back, which undoes a write every other barrier would have missed, on an engine without any read only session above all
        $ownTransaction = !$this->queryConnection->isTransactionActive();
        if ($ownTransaction) {
            $this->queryConnection->beginTransaction();
        }

        try {
            try {
                $result = $this->queryConnection->executeQuery($sql);
            } catch (DriverException $exception) {
                throw new \InvalidArgumentException($this->getSafeErrorMessage($dialect, $exception), previous: $exception);
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
        } finally {
            if ($ownTransaction) {
                $this->rollBack();
            }
        }

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
        // A single kind of line break, so that no engine can end a comment where the tokenizer does not
        $sql = str_replace(["\r\n", "\r"], "\n", rtrim(trim($sql), '; '));

        if (!preg_match('/^(SELECT|WITH)\b/i', $sql)) {
            throw new \InvalidArgumentException('Only a SELECT statement is allowed, optionally introduced by a WITH clause.');
        }

        if (str_contains($sql, ';')) {
            throw new \InvalidArgumentException('Only a single statement is allowed, the query must not contain any semicolon.');
        }

        // MySQL runs the content of a /*! ... */ comment, and MariaDB the one of a /*M! ... */ comment, which would hide SQL from the checks below
        if (str_contains($sql, '/*!') || str_contains($sql, '/*M!')) {
            throw new \InvalidArgumentException('Executable comments (/*! ... */) are not allowed.');
        }

        $forbiddenNames = array_map(fn (string $name) => preg_quote($name, '/'), $this->schemaManager->getForbiddenNames());
        if (preg_match('/(?<![\w$])('.implode('|', $forbiddenNames).')(?![\w$])/i', $sql, $matches)) {
            throw new \InvalidArgumentException(sprintf('"%s" is a forbidden table or column: the query must not mention it.', $matches[1]));
        }

        $dialect = $this->getDialect();
        $tokens = $dialect->tokenize($sql);
        $this->checkStars($tokens);
        $this->checkKeywords($dialect, $tokens);
        $dialect->checkQuery($sql, $tokens);

        return $sql;
    }

    /**
     * Refuse a column wildcard: a star that is not a multiplication, since it follows a keyword or starts a list, or since what comes after it could not follow a multiplication.
     */
    private function checkStars(array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!$token->isSymbol('*')) {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;
            if ($previous?->isSymbol('(') && $next?->isSymbol(')') && ($tokens[$index - 2] ?? null)?->isWord(...self::COUNT_FUNCTIONS)) {
                continue;
            }

            if (null === $previous || $previous->isSymbol('(') || $previous->isSymbol(',') || $previous->isSymbol('.') || $previous->isWord(...self::STAR_WILDCARD_PREFIXES)
                || null === $next || $next->isSymbol(',') || $next->isSymbol(')') || $next->isWord(...self::STAR_WILDCARD_SUFFIXES)) {
                throw new \InvalidArgumentException('Column wildcards (SELECT *, t.*) are not allowed: name every selected column. COUNT(*) is allowed.');
            }
        }
    }

    /**
     * Refuse the keywords that write or read beyond a SELECT, and the forbidden functions of the dialect, called by their name or by a quoted one.
     */
    private function checkKeywords(SqlDialect $dialect, array $tokens): void
    {
        $keywords = [...self::FORBIDDEN_KEYWORDS, ...$dialect->getForbiddenKeywords()];
        $functionPattern = $dialect->getForbiddenFunctionPattern();
        foreach ($tokens as $index => $token) {
            $isCalled = ($tokens[$index + 1] ?? null)?->isSymbol('(') ?? false;
            if ($token->isWord(...$keywords) && !($isCalled && $token->isWord(...self::FUNCTION_KEYWORDS))) {
                throw new \InvalidArgumentException(sprintf('The %s keyword is not allowed.', strtoupper($token->text)));
            }

            if (null !== $functionPattern && $token->isName() && preg_match($functionPattern, $token->text) && $isCalled) {
                throw new \InvalidArgumentException(sprintf('The %s function is not allowed.', $token->text));
            }
        }
    }

    /**
     * Message of a failed query, as the database wrote it when that error only quotes the query or the schema, reduced to the error code otherwise.
     */
    private function getSafeErrorMessage(SqlDialect $dialect, DriverException $exception): string
    {
        if ($dialect->isQuotableError($exception)) {
            return $exception->getMessage();
        }

        return $dialect->getErrorLabel($exception).'. Its message is hidden, since it may quote a stored value.';
    }

    private function rollBack(): void
    {
        try {
            $this->queryConnection->rollBack();
        } catch (DBALException) {
            // The server may have ended the transaction itself on a failed query (a timeout on SQL Server, for instance), leaving nothing to undo
        }
    }
}
