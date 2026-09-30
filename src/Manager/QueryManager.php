<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tknoweb\AiSqlAssistantBundle\Dialect\SqlDialect;
use Tknoweb\AiSqlAssistantBundle\Dialect\SqlToken;

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
    // Words that may follow SELECT before its first column, and the words that end its list of columns
    private const SELECT_MODIFIERS = ['distinct', 'distinctrow', 'all', 'high_priority', 'straight_join', 'sql_small_result', 'sql_big_result', 'sql_buffer_result', 'sql_no_cache', 'sql_calc_found_rows'];
    private const SELECT_LIST_ENDS = ['from', 'into', 'where', 'group', 'having', 'window', 'order', 'limit', 'union', 'intersect', 'except'];
    // Words that follow a table of a FROM clause without being its alias
    private const TABLE_REFERENCE_ENDS = ['on', 'using', 'where', 'join', 'inner', 'left', 'right', 'full', 'outer', 'cross', 'natural', 'straight_join', 'lateral', 'group', 'having', 'window', 'order',
        'limit', 'offset', 'fetch', 'union', 'intersect', 'except', 'for', 'with'];

    private ?SqlDialect $dialect = null;

    public function __construct(
        #[Autowire(service: 'tknoweb_ai_sql_assistant.query_connection')]
        private readonly Connection $queryConnection,
        private readonly SchemaManager $schemaManager,
        #[Autowire('%tknoweb_ai_sql_assistant.coded_columns%')]
        private readonly array $codedColumns = [],
        #[Autowire('%tknoweb_ai_sql_assistant.max_decimals%')]
        private readonly int $maxDecimals = 2,
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
     * Decimals a number of a result keeps at most.
     */
    public function getMaxDecimals(): int
    {
        return $this->maxDecimals;
    }

    /**
     * Run a single SELECT statement and return its column names, at most $maxRows rows, whether rows were left out beyond that limit, and the columns holding codes (see getCodedColumns()).
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

                $rows[] = array_map($this->roundNumber(...), $row);
            }

            $result->free();
        } finally {
            if ($ownTransaction) {
                $this->rollBack();
            }
        }

        $columns = array_keys($rows[0] ?? []);

        return [
            'columns' => $columns,
            'rows' => $rows,
            'truncated' => $truncated,
            'codedColumns' => $this->getCodedColumns($dialect->tokenize($sql), $columns),
        ];
    }

    /**
     * A number keeps $maxDecimals decimals at most, without trailing zeros: most engines return a decimal as a string padded with zeros up to the scale of its column ("18447981.000000"),
     * and an average with every decimal they computed ("527.9891891892"). Rounding it here gives the table, the answer sentence, the chart and the Excel file the same number. A decimal string
     * that needs no rounding stays exact, a float being unable to hold every digit of a wide decimal column; any other value is kept as it is.
     */
    private function roundNumber(mixed $value): mixed
    {
        if (is_float($value)) {
            $rounded = round($value, $this->maxDecimals);

            // Rounding a small negative number gives a negative zero, which would be displayed as "-0"
            return 0.0 === $rounded ? 0.0 : $rounded;
        }

        if (!is_string($value) || !preg_match('/^-?\d+\.\d+$/', $value)) {
            return $value;
        }

        $rounded = rtrim(rtrim($value, '0'), '.');
        $decimals = str_contains($rounded, '.') ? strlen($rounded) - strpos($rounded, '.') - 1 : 0;
        if ($decimals > $this->maxDecimals) {
            $rounded = number_format((float) $value, $this->maxDecimals, '.', '');
            if (str_contains($rounded, '.')) {
                $rounded = rtrim(rtrim($rounded, '0'), '.');
            }
        }

        return '-0' === $rounded ? '0' : $rounded;
    }

    /**
     * Result columns holding the codes of a coded column, so that the application displays their labels, whatever the alias the model gave them: a column selected as it is, without any
     * expression around it, from a table whose mapping backs it with an enum or a discriminator, or from another source (a view) when the "coded_columns" configuration names it. Only the columns
     * of the main SELECT are read, the tables and aliases of the whole query telling where they come from; an alias naming two different tables leaves its columns out.
     */
    private function getCodedColumns(array $tokens, array $columns): array
    {
        if ([] === $columns) {
            return [];
        }

        $resultColumns = array_combine(array_map('mb_strtolower', $columns), $columns);
        $tables = array_change_key_case($this->schemaManager->getTables());
        $codedColumnNames = array_map('mb_strtolower', array_keys($this->codedColumns));
        $sources = $this->getTableSources($tokens);

        $codedColumns = [];
        foreach ($this->getSelectedItems($tokens) as $item) {
            $selectedColumn = $this->readSelectedColumn($item);
            if (null === $selectedColumn || !isset($resultColumns[$selectedColumn['name']])) {
                continue;
            }

            // A qualified column comes from the table its qualifier names (a table without alias, or a derived table, when the qualifier is no known alias), an unqualified one from any table
            // of the query that has it
            $qualifier = $selectedColumn['qualifier'];
            if (null === $qualifier) {
                $candidateTables = array_unique(array_filter(array_values($sources)));
            } elseif (!array_key_exists($qualifier, $sources)) {
                $candidateTables = [$qualifier];
            } elseif (null !== $sources[$qualifier]) {
                $candidateTables = [$sources[$qualifier]];
            } else {
                continue;
            }

            $mappedCodes = [];
            foreach ($candidateTables as $table) {
                $mappedColumns = array_change_key_case($tables[$table]['columns'] ?? []);
                if (isset($mappedColumns[$selectedColumn['column']])) {
                    $mappedCodes[] = isset($mappedColumns[$selectedColumn['column']]['codes']);
                }
            }

            $isCoded = [] !== $mappedCodes ? !in_array(false, $mappedCodes, true) : in_array($selectedColumn['column'], $codedColumnNames, true);
            if ($isCoded) {
                $codedColumns[] = $resultColumns[$selectedColumn['name']];
            }
        }

        return array_values(array_unique($codedColumns));
    }

    /**
     * Tables of the FROM and JOIN clauses of the whole query, derived tables apart, by their alias and by their own name, both in lower case: null for a name standing for two different tables.
     */
    private function getTableSources(array $tokens): array
    {
        $sources = [];
        $addSource = function (string $name, string $table) use (&$sources): void {
            $sources[$name] = array_key_exists($name, $sources) && $sources[$name] !== $table ? null : $table;
        };

        foreach ($tokens as $index => $token) {
            if (!$token->isWord('from', 'join')) {
                continue;
            }

            $position = $index + 1;
            while (null !== ($reference = $this->readTableReference($tokens, $position))) {
                [$table, $alias, $position] = $reference;
                $addSource($table, $table);
                if (null !== $alias) {
                    $addSource($alias, $table);
                }

                // A FROM clause may list several tables separated by commas
                if (!$token->isWord('from') || !($tokens[$position] ?? null)?->isSymbol(',')) {
                    break;
                }

                ++$position;
            }
        }

        return $sources;
    }

    /**
     * Table and alias of a table reference starting at $position, and the position right after it: null for a derived table or anything else than a name. A name qualified by a schema keeps
     * only its table.
     */
    private function readTableReference(array $tokens, int $position): ?array
    {
        if (!($tokens[$position] ?? null)?->isName()) {
            return null;
        }

        $table = $tokens[$position]->getName();
        ++$position;
        while (($tokens[$position] ?? null)?->isSymbol('.') && ($tokens[$position + 1] ?? null)?->isName()) {
            $table = $tokens[$position + 1]->getName();
            $position += 2;
        }

        $alias = null;
        if (($tokens[$position] ?? null)?->isWord('as') && ($tokens[$position + 1] ?? null)?->isName()) {
            $alias = $tokens[$position + 1]->getName();
            $position += 2;
        } elseif (($tokens[$position] ?? null)?->isName() && !$tokens[$position]->isWord(...self::TABLE_REFERENCE_ENDS)) {
            $alias = $tokens[$position]->getName();
            ++$position;
        }

        return [$table, $alias, $position];
    }

    /**
     * Tokens of each selected item of the main SELECT, the first one outside any parenthesis (after the WITH clause, whose queries are between parentheses).
     */
    private function getSelectedItems(array $tokens): array
    {
        $depth = 0;
        $position = null;
        foreach ($tokens as $index => $token) {
            $depth += $token->isSymbol('(') ? 1 : ($token->isSymbol(')') ? -1 : 0);
            if (0 === $depth && $token->isWord('select')) {
                $position = $index + 1;
                break;
            }
        }

        if (null === $position) {
            return [];
        }

        $count = count($tokens);
        while ($position < $count && $tokens[$position]->isWord(...self::SELECT_MODIFIERS)) {
            ++$position;
            // DISTINCT ON (...) of PostgreSQL
            if ($tokens[$position - 1]->isWord('distinct') && ($tokens[$position] ?? null)?->isWord('on')) {
                $position = $this->skipParentheses($tokens, $position + 1);
            }
        }

        // TOP 10, TOP (10), then PERCENT and WITH TIES of SQL Server
        if (($tokens[$position] ?? null)?->isWord('top')) {
            $position = ($tokens[$position + 1] ?? null)?->isSymbol('(') ? $this->skipParentheses($tokens, $position + 1) : $position + 2;
            while ($position < $count && $tokens[$position]->isWord('percent', 'with', 'ties')) {
                ++$position;
            }
        }

        $items = [];
        $item = [];
        $depth = 0;
        for (; $position < $count; ++$position) {
            $token = $tokens[$position];
            if (0 === $depth && ($token->isWord(...self::SELECT_LIST_ENDS) || $token->isSymbol(')'))) {
                break;
            }

            if (0 === $depth && $token->isSymbol(',')) {
                $items[] = $item;
                $item = [];

                continue;
            }

            $depth += $token->isSymbol('(') ? 1 : ($token->isSymbol(')') ? -1 : 0);
            $item[] = $token;
        }

        $items[] = $item;

        return $items;
    }

    /**
     * Position right after the parenthesized group starting at $position, or $position itself when no parenthesis starts there.
     */
    private function skipParentheses(array $tokens, int $position): int
    {
        if (!($tokens[$position] ?? null)?->isSymbol('(')) {
            return $position;
        }

        $depth = 0;
        $count = count($tokens);
        for (; $position < $count; ++$position) {
            $depth += $tokens[$position]->isSymbol('(') ? 1 : ($tokens[$position]->isSymbol(')') ? -1 : 0);
            if (0 === $depth) {
                return $position + 1;
            }
        }

        return $position;
    }

    /**
     * Column a selected item reads as it is, with its qualifier (a table or its alias) and the name of its result column, all in lower case: null for any expression.
     */
    private function readSelectedColumn(array $item): ?array
    {
        // The alias comes last, after AS, or right after the column
        $alias = null;
        $count = count($item);
        if ($count >= 3 && $item[$count - 2]->isWord('as')) {
            $alias = $item[$count - 1];
            $item = array_slice($item, 0, $count - 2);
        } elseif ($count >= 2 && $item[$count - 2]->isName()) {
            $alias = $item[$count - 1];
            $item = array_slice($item, 0, $count - 1);
        }

        if (null !== $alias && !$alias->isName() && SqlToken::STRING !== $alias->type) {
            return null;
        }

        // A column, a table and its column, or a schema, a table and its column
        $names = [];
        foreach ($item as $index => $token) {
            if (0 === $index % 2 ? !$token->isName() : !$token->isSymbol('.')) {
                return null;
            }

            if (0 === $index % 2) {
                $names[] = $token->getName();
            }
        }

        if ([] === $names || count($names) > 3 || 0 === count($item) % 2) {
            return null;
        }

        $column = array_pop($names);

        return [
            'column' => $column,
            'qualifier' => [] !== $names ? array_pop($names) : null,
            'name' => null !== $alias ? mb_strtolower($alias->text) : $column,
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
