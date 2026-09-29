<?php

namespace Tknoweb\AiSqlAssistantBundle\Dialect;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * PostgreSQL: a read only session with a time limit on every statement. On top of the common checks, a query must not name a table or its alias on its own, which PostgreSQL reads as the
 * whole row ("SELECT e FROM employee e", "row_to_json(e)"), every column included, like a star.
 */
class PostgreSqlDialect extends SqlDialect
{
    // SQLSTATE of the errors whose message only quotes the query itself or names of the schema, the only ones passed on as they are. Any other message may quote a stored value (an invalid
    // text representation of a number or a date, for instance), which must never reach the model: only its SQLSTATE is passed on then.
    private const QUOTABLE_SQLSTATES = [
        '0A000', // feature not supported
        '21000', // subquery returning more than one row
        '2201W', // invalid LIMIT
        '2201X', // invalid OFFSET
        '22012', // division by zero
        '25006', // write in a read only transaction
        '42501', // insufficient privilege
        '42601', // syntax error, quoting the query near the error
        '42701', // duplicate column
        '42702', // ambiguous column
        '42703', // undefined column
        '42712', // table or alias used twice
        '42725', // ambiguous function
        '42803', // column missing from the GROUP BY
        '42804', // data type mismatch, naming the types
        '42809', // wrong object type
        '42846', // impossible cast, naming the types
        '42883', // undefined function, naming the types of its arguments
        '42P01', // undefined table
        '42P09', // ambiguous alias
        '42P10', // invalid column reference, an ORDER BY missing from a SELECT DISTINCT for instance
        '42P19', // invalid recursion
        '42P20', // window function misplaced
        '57014', // statement timeout
    ];

    // Words ending the FROM clause of a query at its own parenthesis level
    private const FROM_CLAUSE_END_WORDS = ['where', 'group', 'having', 'order', 'limit', 'offset', 'fetch', 'window', 'union', 'intersect', 'except', 'for', 'returning'];
    // Keywords that may follow a relation in a FROM clause, which are therefore not its alias
    private const NOT_ALIAS_WORDS = ['inner', 'left', 'right', 'full', 'outer', 'cross', 'natural', 'join', 'on', 'using', 'tablesample', 'with'];

    public function getName(): string
    {
        return 'PostgreSQL';
    }

    protected function getEngineName(): string
    {
        return 'PostgreSQL';
    }

    public function getErrorLabel(DriverException $exception): string
    {
        return sprintf('PostgreSQL error (SQLSTATE %s)', $exception->getSQLState() ?? 'unknown');
    }

    /**
     * Both settings only last for the session: the time limit applies to every statement, and each transaction started after it is read only, even for a user granted a write by mistake.
     */
    public function prepareSession(Connection $connection, int $timeLimitMilliseconds): void
    {
        $connection->executeStatement('SET statement_timeout = '.$timeLimitMilliseconds);
        $connection->executeStatement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
    }

    public function isQuotableError(DriverException $exception): bool
    {
        return in_array($exception->getSQLState(), self::QUOTABLE_SQLSTATES, true);
    }

    /**
     * Functions reading the files or the settings of the server, reaching another server, running the SQL of a string (query_to_xml, ts_stat...) or reading a whole table (table_to_xml).
     */
    public function getForbiddenFunctionPattern(): ?string
    {
        return '/^(pg_(read|ls|stat_file|file|logdir)\w*|lo_\w+|dblink\w*|set_config|pg_(terminate|cancel)_backend|pg_reload_conf|pg_rotate_logfile|\w+_to_xml\w*|ts_stat|pg_sleep\w*)$/i';
    }

    /**
     * A Unicode escape (U&"...") can spell a forbidden name without writing it, and a table or its alias written on its own reads every column of the table.
     */
    public function checkQuery(string $sql, array $tokens): void
    {
        if (preg_match('/(?<![\w$])U&[\'"]/i', $sql)) {
            throw new \InvalidArgumentException('Unicode escapes (U&\'...\', U&"...") are not allowed.');
        }

        [$relations, $definitions] = $this->findRelations($tokens);
        foreach ($tokens as $index => $token) {
            if (!$token->isName() || !isset($relations[$token->getName()]) || isset($definitions[$index])) {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;
            // A qualified name, a function call, an alias of the select list, or the name of a WITH query
            if ($next?->isSymbol('.') || $next?->isSymbol('(') || $previous?->isSymbol('.') || $previous?->isWord('as') || ($next?->isWord('as') && ($tokens[$index + 2] ?? null)?->isSymbol('('))) {
                continue;
            }

            throw new \InvalidArgumentException(sprintf('"%s" names a table or its alias on its own, which reads every column of the table: qualify each selected column instead (%1$s.name).', $token->text));
        }
    }

    protected function checkTokenStart(string $sql, int $offset): void
    {
        if ('$' === $sql[$offset] && preg_match('/\G\$([\p{L}_][\p{L}\p{N}_]*)?\$/u', $sql, $matches, 0, $offset)) {
            throw new \InvalidArgumentException('Dollar-quoted strings are not allowed: write strings between single quotes.');
        }
    }

    /**
     * Names of the relations of the FROM clauses of a query, tables and aliases alike, and the indexes of the tokens that define them. Each parenthesis level is followed on its own, a
     * subquery or a parenthesized join having its own FROM clause, and a FROM only starts one after a SELECT of its level (not in EXTRACT(YEAR FROM ...), nor in IS DISTINCT FROM).
     */
    private function findRelations(array $tokens): array
    {
        $relations = [];
        $definitions = [];
        // By parenthesis level: whether it holds a SELECT, whether it is in its FROM clause, what the next token is expected to be ("relation", "function" or "alias"), and whether its
        // closing parenthesis ends a relation, which an alias may then follow
        $levels = [['select' => false, 'from' => false, 'expect' => null, 'closesRelation' => false]];
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            $level = count($levels) - 1;

            if ($token->isSymbol('(')) {
                $expect = $levels[$level]['expect'];
                $levels[$level]['expect'] = null;
                $levels[] = [
                    'select' => false,
                    'from' => 'relation' === $expect,
                    'expect' => 'relation' === $expect ? 'relation' : null,
                    'closesRelation' => 'relation' === $expect || 'function' === $expect,
                ];

                continue;
            }

            if ($token->isSymbol(')')) {
                if ($level > 0 && array_pop($levels)['closesRelation']) {
                    $levels[$level - 1]['expect'] = 'alias';
                }

                continue;
            }

            // In a FROM clause, WITH can only be the one of WITH ORDINALITY, which keeps the clause going
            if ($token->isWord('select', 'values') || ($token->isWord('with') && !$levels[$level]['from'])) {
                $levels[$level] = ['select' => $levels[$level]['select'] || $token->isWord('select'), 'from' => false, 'expect' => null] + $levels[$level];

                continue;
            }

            if ($token->isWord('from')) {
                if ($levels[$level]['select'] && !($tokens[$index - 1] ?? null)?->isWord('distinct')) {
                    $levels[$level]['from'] = true;
                    $levels[$level]['expect'] = 'relation';
                }

                continue;
            }

            if (!$levels[$level]['from']) {
                continue;
            }

            if ($token->isWord(...self::FROM_CLAUSE_END_WORDS)) {
                $levels[$level]['from'] = false;
                $levels[$level]['expect'] = null;
            } elseif ($token->isWord('join') || $token->isSymbol(',')) {
                $levels[$level]['expect'] = 'relation';
            } elseif ($token->isWord('on', 'using')) {
                $levels[$level]['expect'] = null;
            } elseif ('relation' === $levels[$level]['expect']) {
                if ($token->isWord('only', 'lateral')) {
                    continue;
                }

                $levels[$level]['expect'] = null;
                if ($token->isName()) {
                    // A schema-qualified relation is exposed under its last name
                    while (($tokens[$index + 1] ?? null)?->isSymbol('.') && ($tokens[$index + 2] ?? null)?->isName()) {
                        $index += 2;
                    }

                    $relations[$tokens[$index]->getName()] = true;
                    $definitions[$index] = true;
                    $levels[$level]['expect'] = ($tokens[$index + 1] ?? null)?->isSymbol('(') ? 'function' : 'alias';
                }
            } elseif ('alias' === $levels[$level]['expect'] && !$token->isWord('as')) {
                $levels[$level]['expect'] = null;
                if ($token->isName() && !$token->isWord(...self::NOT_ALIAS_WORDS)) {
                    $relations[$token->getName()] = true;
                    $definitions[$index] = true;
                }
            }
        }

        return [$relations, $definitions];
    }
}
