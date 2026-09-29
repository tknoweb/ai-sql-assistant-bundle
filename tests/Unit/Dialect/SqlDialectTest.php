<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Dialect;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\TestCase;
use Tknoweb\AiSqlAssistantBundle\Dialect\MariaDbDialect;
use Tknoweb\AiSqlAssistantBundle\Dialect\MySqlDialect;
use Tknoweb\AiSqlAssistantBundle\Dialect\PostgreSqlDialect;
use Tknoweb\AiSqlAssistantBundle\Dialect\SqlDialect;
use Tknoweb\AiSqlAssistantBundle\Dialect\SqliteDialect;
use Tknoweb\AiSqlAssistantBundle\Dialect\SqlServerDialect;
use Tknoweb\AiSqlAssistantBundle\Dialect\SqlToken;

/**
 * The dialect of each engine, found from its DBAL platform, and the reading of a query as that engine reads it, on which every check of QueryManager relies.
 */
class SqlDialectTest extends TestCase
{
    public function testFindsTheDialectOfTheEngine(): void
    {
        $this->assertInstanceOf(MySqlDialect::class, SqlDialect::fromPlatform(new MySQL80Platform()));
        $this->assertInstanceOf(MariaDbDialect::class, SqlDialect::fromPlatform(new MariaDBPlatform()));
        $this->assertInstanceOf(PostgreSqlDialect::class, SqlDialect::fromPlatform(new PostgreSQLPlatform()));
        $this->assertInstanceOf(SqlServerDialect::class, SqlDialect::fromPlatform(new SQLServerPlatform()));
        $this->assertInstanceOf(SqliteDialect::class, SqlDialect::fromPlatform(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])->getDatabasePlatform()));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not supported by the assistant');
        SqlDialect::fromPlatform(new OraclePlatform());
    }

    public function testReadsTheQuotesAndCommentsOfMysql(): void
    {
        $this->assertSame([
            [SqlToken::WORD, 'SELECT'],
            [SqlToken::QUOTED, 'a`b'],
            [SqlToken::SYMBOL, ','],
            [SqlToken::QUOTED, 'c'],
            [SqlToken::SYMBOL, ','],
            [SqlToken::STRING, "it's"],
            [SqlToken::SYMBOL, ','],
            [SqlToken::NUMBER, '1.5e3'],
            [SqlToken::SYMBOL, '-'],
            [SqlToken::SYMBOL, '-'],
            [SqlToken::NUMBER, '2'],
            [SqlToken::WORD, 'FROM'],
            [SqlToken::WORD, 'cafés'],
        ], $this->tokenize(new MySqlDialect(), "SELECT `a``b`, \"c\", 'it''s', 1.5e3 - -2 -- comment\n# comment\nFROM /* comment */ cafés\n#"));
    }

    public function testReadsTheQuotesAndCommentsOfSqlServer(): void
    {
        $this->assertSame([
            [SqlToken::WORD, 'SELECT'],
            [SqlToken::WORD, 'TOP'],
            [SqlToken::NUMBER, '5'],
            [SqlToken::QUOTED, 'a b'],
            [SqlToken::SYMBOL, ','],
            [SqlToken::QUOTED, 'c'],
            [SqlToken::WORD, 'FROM'],
            [SqlToken::SYMBOL, '#'],
            [SqlToken::WORD, 'temp'],
            [SqlToken::SYMBOL, '`'],
        ], $this->tokenize(new SqlServerDialect(), "SELECT TOP 5 [a b], \"c\" FROM #temp --comment\n`"));
    }

    public function testReadsTheQuotesAndCommentsOfPostgresql(): void
    {
        $this->assertSame([
            [SqlToken::WORD, 'SELECT'],
            [SqlToken::QUOTED, 'A'],
            [SqlToken::SYMBOL, ','],
            [SqlToken::SYMBOL, '$'],
            [SqlToken::NUMBER, '1'],
            [SqlToken::SYMBOL, ','],
            [SqlToken::WORD, 'a$b'],
            [SqlToken::SYMBOL, ':'],
            [SqlToken::SYMBOL, ':'],
            [SqlToken::WORD, 'text'],
            [SqlToken::SYMBOL, '['],
            [SqlToken::NUMBER, '1'],
            [SqlToken::SYMBOL, ']'],
        ], $this->tokenize(new PostgreSqlDialect(), 'SELECT "A", $1, a$b::text[1] --comment'));
    }

    public function testRefusesWhatAnEngineCouldReadInAnotherWay(): void
    {
        $refusals = [
            [new MySqlDialect(), "SELECT 'a\\'", 'A backslash is not allowed'],
            [new SqlServerDialect(), 'SELECT "a\\b"', 'A backslash is not allowed'],
            [new MySqlDialect(), 'SELECT 1--1', 'Two minus signs'],
            [new PostgreSqlDialect(), 'SELECT 1 /* a /* b */', 'must not hold "/*"'],
            [new MySqlDialect(), 'SELECT 1 /* a', 'unterminated comment'],
            [new MySqlDialect(), 'SELECT `a', 'unterminated string or quoted name'],
            [new SqliteDialect(), 'SELECT [a]]b]', 'must not hold a closing bracket'],
            [new PostgreSqlDialect(), 'SELECT $$a$$', 'Dollar-quoted strings'],
            [new MySqlDialect(), "SELECT\u{00A0}1", 'Unexpected character at position 6'],
            [new MySqlDialect(), "SELECT\f1", 'Unexpected character'],
            [new MySqlDialect(), "SELECT 1\x00", 'Unexpected character'],
        ];

        foreach ($refusals as [$dialect, $sql, $expectedMessage]) {
            try {
                $dialect->tokenize($sql);
                $this->fail(sprintf('The query "%s" should have been refused.', $sql));
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString($expectedMessage, $exception->getMessage(), $sql);
            }
        }
    }

    public function testTellsTheSqlTheModelWritesIn(): void
    {
        $this->assertSame('MySQL 8', (new MySqlDialect())->getName());
        $this->assertSame('MariaDB', (new MariaDbDialect())->getName());
        $this->assertSame('PostgreSQL', (new PostgreSqlDialect())->getName());
        $this->assertSame('SQL Server (Transact-SQL)', (new SqlServerDialect())->getName());
        $this->assertSame('SQLite', (new SqliteDialect())->getName());
    }

    private function tokenize(SqlDialect $dialect, string $sql): array
    {
        return array_map(fn (SqlToken $token) => [$token->type, $token->text], $dialect->tokenize($sql));
    }
}
