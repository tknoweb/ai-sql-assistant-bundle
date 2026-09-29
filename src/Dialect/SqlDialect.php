<?php

namespace Tknoweb\AiSqlAssistantBundle\Dialect;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;

/**
 * What the assistant does differently from one database engine to another, everything else going through DBAL: the SQL the model writes and how it is read to be checked, the settings of
 * the session its queries run in, the errors whose message may reach the model, and how the flattening swaps its tables.
 * The checks of QueryManager only hold if they read a query as the engine does, so the tokenizer refuses whatever an engine could read in another way depending on its version or its
 * settings: a backslash in a string or a quoted name (an escape character or not), a comment inside a comment (nested or not), a character outside ASCII that is not a letter (a space or not).
 */
abstract class SqlDialect
{
    // Keywords starting a statement that writes data or ends a transaction, which a SELECT never holds (a WITH clause can introduce an UPDATE or a DELETE)
    private const STATEMENT_KEYWORDS = ['insert', 'update', 'delete', 'merge', 'truncate', 'drop', 'alter', 'create', 'grant', 'revoke', 'call', 'exec', 'execute', 'begin', 'commit', 'rollback'];

    public static function fromPlatform(AbstractPlatform $platform): self
    {
        return match (true) {
            $platform instanceof MariaDBPlatform => new MariaDbDialect(),
            $platform instanceof AbstractMySQLPlatform => new MySqlDialect(),
            $platform instanceof PostgreSQLPlatform => new PostgreSqlDialect(),
            $platform instanceof SQLServerPlatform => new SqlServerDialect(),
            $platform instanceof SQLitePlatform => new SqliteDialect(),
            default => throw new \LogicException(sprintf('The database platform %s is not supported by the assistant, only MySQL, MariaDB, PostgreSQL, SQL Server and SQLite are.', $platform::class)),
        };
    }

    /**
     * Name of the SQL the model writes its queries in, for its instructions.
     */
    abstract public function getName(): string;

    /**
     * Settings of the session applied before each query of the model: its time limit, and the read only mode where the engine has one.
     */
    abstract public function prepareSession(Connection $connection, int $timeLimitMilliseconds): void;

    /**
     * Whether the message of an error only quotes the query or names of the schema, never a stored value, so that it may be passed on to the model.
     */
    abstract public function isQuotableError(DriverException $exception): bool;

    /**
     * Name of the engine in the errors passed on to the model.
     */
    abstract protected function getEngineName(): string;

    /**
     * Error reported to the model in place of a message that may quote a stored value.
     */
    public function getErrorLabel(DriverException $exception): string
    {
        return sprintf('%s error %d (SQLSTATE %s)', $this->getEngineName(), $exception->getCode(), $exception->getSQLState() ?? 'unknown');
    }

    /**
     * Keywords a query of the model must never hold outside a string or a quoted name.
     */
    public function getForbiddenKeywords(): array
    {
        return self::STATEMENT_KEYWORDS;
    }

    /**
     * Pattern of the names of the functions a query of the model must never call: reading a file of the server, running SQL written in a string, reaching another server...
     */
    public function getForbiddenFunctionPattern(): ?string
    {
        return null;
    }

    /**
     * Checks of a query specific to this dialect, after the common ones of QueryManager.
     */
    public function checkQuery(string $sql, array $tokens): void
    {
    }

    /**
     * Tokens of a query, comments and spaces left out. Only a space, a tab or a line feed separates two tokens: QueryManager turns the other line breaks into line feeds first.
     */
    public function tokenize(string $sql): array
    {
        $tokens = [];
        $quotes = $this->getQuotes();
        $length = strlen($sql);
        $offset = 0;
        while ($offset < $length) {
            $character = $sql[$offset];
            if (' ' === $character || "\t" === $character || "\n" === $character) {
                ++$offset;

                continue;
            }

            if (0 === substr_compare($sql, '/*', $offset, 2)) {
                $offset = $this->skipBlockComment($sql, $offset);

                continue;
            }

            if (0 === substr_compare($sql, '--', $offset, 2)) {
                if (!$this->isDoubleDashComment($sql, $offset)) {
                    throw new \InvalidArgumentException('Two minus signs must be separated by a space, "--" starting a comment.');
                }

                $offset = $this->skipLineComment($sql, $offset);

                continue;
            }

            if ('#' === $character && $this->hasHashComments()) {
                $offset = $this->skipLineComment($sql, $offset);

                continue;
            }

            if (isset($quotes[$character])) {
                [$token, $offset] = $this->readQuoted($sql, $offset, $quotes[$character]);
                $tokens[] = $token;

                continue;
            }

            $this->checkTokenStart($sql, $offset);

            if (preg_match('/\G[\p{L}_][\p{L}\p{N}_$]*/u', $sql, $matches, 0, $offset)) {
                $tokens[] = new SqlToken(SqlToken::WORD, $matches[0]);
            } elseif (preg_match('/\G(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/', $sql, $matches, 0, $offset)) {
                $tokens[] = new SqlToken(SqlToken::NUMBER, $matches[0]);
            } elseif (preg_match('/\G[!-\/:-@\[-`{-~]/', $sql, $matches, 0, $offset)) {
                $tokens[] = new SqlToken(SqlToken::SYMBOL, $matches[0]);
            } else {
                throw new \InvalidArgumentException(sprintf('Unexpected character at position %d of the query: outside strings, only letters, digits, ASCII punctuation, spaces, tabs and line feeds are allowed.', $offset));
            }

            $offset += strlen($matches[0]);
        }

        return $tokens;
    }

    /**
     * Characters opening a string or a quoted name, with the one closing it.
     */
    protected function getQuotes(): array
    {
        return ["'" => "'", '"' => '"'];
    }

    protected function hasHashComments(): bool
    {
        return false;
    }

    protected function isDoubleDashComment(string $sql, int $offset): bool
    {
        return true;
    }

    /**
     * Refuses a token of this dialect that the tokenizer does not read, at the start of any token that is neither a comment nor a quoted one.
     */
    protected function checkTokenStart(string $sql, int $offset): void
    {
    }

    /**
     * Largest number of parameters a single statement may bind, which bounds the rows inserted at once by the flattening.
     */
    public function getMaxParameters(): int
    {
        return 65535;
    }

    /**
     * Create the empty copy of a flat table the flattening fills, $columns being the columns it writes: here a table holding only those columns, whose rows swapCopies() copies into the live
     * table, which keeps its indexes and its identity.
     */
    public function createCopy(Connection $connection, string $table, string $copy, array $columns): void
    {
        $platform = $connection->getDatabasePlatform();
        $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', self::quoteName($platform, $copy)));
        $connection->executeStatement(sprintf('CREATE TABLE %s AS SELECT %s FROM %s WHERE 1 = 0', self::quoteName($platform, $copy), self::quoteNames($platform, $columns), self::quoteName($platform, $table)));
    }

    /**
     * Replace the rows of the live tables with those of their filled copy, $copies giving the copy of each table and $columns its columns: in a single transaction, so that a query sees
     * either the previous rows of every table or the new ones, the copies being dropped after.
     */
    public function swapCopies(Connection $connection, array $copies, array $columns): void
    {
        $platform = $connection->getDatabasePlatform();
        $connection->beginTransaction();
        try {
            foreach ($copies as $table => $copy) {
                $connection->executeStatement(sprintf('DELETE FROM %s', self::quoteName($platform, $table)));
                $connection->executeStatement(sprintf('INSERT INTO %1$s (%2$s) SELECT %2$s FROM %3$s', self::quoteName($platform, $table), self::quoteNames($platform, $columns[$table]), self::quoteName($platform, $copy)));
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        foreach ($copies as $copy) {
            $connection->executeStatement(sprintf('DROP TABLE %s', self::quoteName($platform, $copy)));
        }
    }

    /**
     * A table name, schema-qualified or not, or a column name, quoted for the platform.
     */
    public static function quoteName(AbstractPlatform $platform, string $name): string
    {
        return implode('.', array_map(fn (string $part) => $platform->quoteSingleIdentifier($part), explode('.', $name)));
    }

    public static function quoteNames(AbstractPlatform $platform, array $names): string
    {
        return implode(', ', array_map(fn (string $name) => self::quoteName($platform, $name), $names));
    }

    private function skipBlockComment(string $sql, int $offset): int
    {
        $end = strpos($sql, '*/', $offset + 2);
        if (false === $end) {
            throw new \InvalidArgumentException('The query holds an unterminated comment.');
        }

        if (str_contains(substr($sql, $offset + 2, $end - $offset - 2), '/*')) {
            throw new \InvalidArgumentException('A comment must not hold "/*", since some databases nest comments and others do not.');
        }

        return $end + 2;
    }

    private function skipLineComment(string $sql, int $offset): int
    {
        $end = strpos($sql, "\n", $offset);

        return false === $end ? strlen($sql) : $end + 1;
    }

    /**
     * A string or a quoted name, and the offset following it. A doubled closing quote stands for the quote itself, in brackets apart: SQL Server reads "]]" so, SQLite ends the name there.
     */
    private function readQuoted(string $sql, int $offset, string $closing): array
    {
        $text = '';
        $position = $offset + 1;
        while (true) {
            $end = strpos($sql, $closing, $position);
            if (false === $end) {
                throw new \InvalidArgumentException('The query holds an unterminated string or quoted name.');
            }

            $text .= substr($sql, $position, $end - $position);
            if ($closing !== ($sql[$end + 1] ?? null)) {
                break;
            }

            if (']' === $closing) {
                throw new \InvalidArgumentException('A name between brackets must not hold a closing bracket.');
            }

            $text .= $closing;
            $position = $end + 2;
        }

        if (str_contains($text, '\\')) {
            throw new \InvalidArgumentException('A backslash is not allowed in a string or a quoted name, some databases reading it as an escape character.');
        }

        return [new SqlToken("'" === $sql[$offset] ? SqlToken::STRING : SqlToken::QUOTED, $text), $end + 1];
    }
}
