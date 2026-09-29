<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Manager;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\TestCase;
use Tknoweb\AiSqlAssistantBundle\Manager\QueryManager;
use Tknoweb\AiSqlAssistantBundle\Manager\SchemaManager;

/**
 * The checks of the queries of the model, and the filtering of the errors of the database sent back to it: the two barriers between the model and the stored values, on each engine.
 */
class QueryManagerTest extends TestCase
{
    private const FORBIDDEN_NAMES = ['secret', 'password', 'conversation', 'messenger_messages'];
    // Error of the simulated database, which tells that a query passed the checks
    private const DATABASE_ERROR = "Unknown column 'x' in 'field list'";

    public function testRefusesAnythingElseThanASingleSelect(): void
    {
        $refusedQueries = [
            'UPDATE store SET name = 1' => 'Only a SELECT statement is allowed',
            'DELETE FROM store' => 'Only a SELECT statement is allowed',
            'SHOW TABLES' => 'Only a SELECT statement is allowed',
            'DESCRIBE store' => 'Only a SELECT statement is allowed',
            '(SELECT 1)' => 'Only a SELECT statement is allowed',
            ' -- comment first'."\n".'SELECT 1' => 'Only a SELECT statement is allowed',
            'SELECT 1; DROP TABLE store' => 'Only a single statement is allowed',
            "SELECT ';' AS semicolon" => 'Only a single statement is allowed',
            'SELECT 1 /*!50000 , name */ FROM store' => 'Executable comments',
            'SELECT 1 /*M! , name */ FROM store' => 'Executable comments',
            'SELECT name FROM store INTO OUTFILE \'/tmp/stores\'' => 'The INTO keyword is not allowed',
            'SELECT name INTO @name FROM store' => 'The INTO keyword is not allowed',
            'WITH copy AS (TABLE store) SELECT name FROM copy' => 'The TABLE keyword is not allowed',
            // A WITH clause may introduce a statement that writes
            "WITH s AS (SELECT id FROM store) UPDATE store SET name = 'x'" => 'The UPDATE keyword is not allowed',
            'WITH s AS (SELECT id FROM store) DELETE FROM store' => 'The DELETE keyword is not allowed',
            'SELECT name FROM store FOR UPDATE' => 'The UPDATE keyword is not allowed',
            "SELECT LOAD_FILE('/etc/passwd') AS content" => 'The LOAD_FILE function is not allowed',
        ];

        foreach ($refusedQueries as $sql => $expectedMessage) {
            $this->assertRefused(new MySQLPlatform(), $sql, $expectedMessage);
        }

        $this->assertRefused(new PostgreSQLPlatform(), "WITH added AS (INSERT INTO region (name) VALUES ('x') RETURNING id) SELECT added.id FROM added", 'The INSERT keyword is not allowed');
        $this->assertRefused(new SQLServerPlatform(), 'WITH c AS (SELECT 1 AS n) INSERT region SELECT c.n FROM c', 'The INSERT keyword is not allowed');
        // INSERT() and TRUNCATE() are functions of MySQL as well
        $this->assertAccepted(new MySQLPlatform(), "SELECT TRUNCATE(AVG(s.id), 2) AS average, INSERT(s.name, 1, 1, 'X') AS renamed FROM store s");
    }

    public function testRefusesAnyForbiddenNameWhereverItIsWritten(): void
    {
        $refusedQueries = [
            'SELECT id FROM secret',
            'SELECT id FROM SECRET',
            'SELECT m.password FROM employee m',
            'SELECT id FROM `secret`',
            'SELECT id FROM [secret]',
            'SELECT id FROM "secret"',
            "SELECT id FROM store WHERE name = 'password'",
            'SELECT id FROM store -- secret',
            'SELECT id FROM store /* conversation */',
            'SELECT COUNT(*) FROM messenger_messages',
            'WITH s AS (SELECT id FROM secret) SELECT id FROM s',
        ];

        foreach ($refusedQueries as $sql) {
            $this->assertRefused(new MySQLPlatform(), $sql, 'is a forbidden table or column');
        }
    }

    public function testAcceptsANameThatOnlyContainsAForbiddenOne(): void
    {
        $queryManager = $this->createQueryManagerOnSqlite();

        foreach (['SELECT 1 AS password_hint', 'SELECT 1 AS secrets', 'SELECT 1 AS my_secret', 'SELECT 1 AS conversation_count'] as $sql) {
            $this->assertSame([1], array_map('intval', array_values($queryManager->execute($sql, 10)['rows'][0])), $sql);
        }
    }

    public function testRefusesColumnWildcards(): void
    {
        $refusedQueries = [
            'mysql' => [
                'SELECT * FROM store',
                'select * from store',
                'SELECT s.* FROM store s',
                'SELECT s . * FROM store s',
                'SELECT DISTINCT * FROM store',
                'SELECT name, * FROM store',
                'SELECT (SELECT * FROM region) FROM store',
                "SELECT\n*\nFROM store",
                'SELECT SQL_NO_CACHE * FROM store',
                'WITH copy AS (SELECT * FROM store) SELECT name FROM copy',
                // A quote in a comment must not open a string that would hide the star from the checks
                "SELECT name /* ' */, s.* FROM store s /* ' */",
                "SELECT name -- '\n, s.* FROM store s -- '",
                "SELECT name # '\n, s.* FROM store s # '",
                "SELECT name\r, s.* FROM store s",
            ],
            'sqlserver' => [
                'SELECT TOP 10 * FROM store',
                'SELECT TOP (5) PERCENT WITH TIES * FROM store ORDER BY name',
                'SELECT DISTINCT TOP 3 *, name FROM store',
                'SELECT [name] FROM store UNION SELECT * FROM region',
            ],
            'postgresql' => [
                'SELECT name, * FROM store',
                'SELECT (s).* FROM store s',
                'SELECT count(s.*) AS n FROM store s',
            ],
        ];

        foreach ($refusedQueries as $engine => $queries) {
            foreach ($queries as $sql) {
                $this->assertRefused($this->createPlatform($engine), $sql, 'Column wildcards');
            }
        }
    }

    public function testAcceptsTheStarsThatAreNoWildcard(): void
    {
        $queryManager = $this->createQueryManagerOnSqlite();
        $acceptedQueries = [
            'SELECT COUNT(*) AS n' => 1,
            'SELECT count( * ) AS n' => 1,
            'SELECT 2 * 3 AS n' => 6,
            'SELECT 2*3 AS n' => 6,
            'SELECT (1 + 1) * (2 + 1) AS n' => 6,
            "SELECT LENGTH('*') AS n" => 1,
            "SELECT LENGTH('it''s * a table') AS n" => 14,
            'SELECT 1 AS n -- SELECT * FROM store' => 1,
            'SELECT 1 AS n /* SELECT * INTO */' => 1,
            'SELECT 1 AS n, 2 AS `table`' => 1,
            'SELECT 1 AS n, 2 AS [into]' => 1,
            "SELECT LENGTH('table into') AS n" => 10,
            "SELECT LENGTH('café') AS n" => 4,
            'WITH t AS (SELECT 4 AS n) SELECT n FROM t' => 4,
            'SELECT 5 AS n;' => 5,
            "  SELECT 5 AS n ; \n" => 5,
            "SELECT 6 AS n\r\nWHERE 1 = 1" => 6,
        ];

        foreach ($acceptedQueries as $sql => $expectedValue) {
            $this->assertSame($expectedValue, (int) $queryManager->execute($sql, 10)['rows'][0]['n'], $sql);
        }

        $this->assertAccepted(new SQLServerPlatform(), 'SELECT COUNT_BIG(*) AS n FROM store');
        $this->assertAccepted(new SQLServerPlatform(), 'SELECT TOP 10 [name], 2 * [id] AS [double] FROM store ORDER BY [name]');
    }

    public function testRefusesWhatAnEngineCouldReadInAnotherWay(): void
    {
        $refusedQueries = [
            // With backslash escapes, MySQL ends the string at the last quote, and without them at the second one
            ['mysql', "SELECT 'a\\', s.* FROM store s -- '", 'A backslash is not allowed'],
            ['postgresql', "SELECT E'a\\', s.* FROM store s -- '", 'A backslash is not allowed'],
            ['mysql', 'SELECT 1--1 AS n, s.* FROM store s', 'Two minus signs must be separated by a space'],
            // PostgreSQL and SQL Server nest comments, MySQL does not
            ['postgresql', "SELECT name /* /* */ ' */, s.* FROM store s -- '", 'A comment must not hold "/*"'],
            ['sqlserver', "SELECT name /* /* */ ' */, s.* FROM store s -- '", 'A comment must not hold "/*"'],
            ['mysql', 'SELECT name /* never closed', 'unterminated comment'],
            ['mysql', "SELECT 'never closed", 'unterminated string or quoted name'],
            ['sqlserver', 'SELECT [a]]b] FROM store', 'must not hold a closing bracket'],
            ['postgresql', "SELECT \$\$ ' \$\$, s.* FROM store s", 'Dollar-quoted strings are not allowed'],
            ['postgresql', "SELECT \$tag\$ ' \$tag\$ AS n", 'Dollar-quoted strings are not allowed'],
            ['postgresql', "SELECT U&\"p!0061ssword\" UESCAPE '!' FROM employee", 'Unicode escapes'],
            // An engine could read a non-breaking space as a space, and see "SELECT * FROM" where the checks see two names
            ['sqlserver', "SELECT\u{00A0}*\u{00A0}FROM store", 'Unexpected character'],
            ['mysql', "SELECT name FROM store\x0BWHERE 1", 'Unexpected character'],
        ];

        foreach ($refusedQueries as [$engine, $sql, $expectedMessage]) {
            $this->assertRefused($this->createPlatform($engine), $sql, $expectedMessage);
        }
    }

    public function testRefusesTheStatementsASqlServerBatchCouldChain(): void
    {
        $refusedQueries = [
            "SELECT name FROM store WAITFOR DELAY '00:00:10'" => 'The WAITFOR keyword',
            "SELECT 1 AS n EXEC xp_cmdshell 'dir'" => 'The EXEC keyword',
            'SELECT 1 AS n SET NOCOUNT ON' => 'The SET keyword',
            'SELECT 1 AS n USE master' => 'The USE keyword',
            'SELECT 1 AS n DECLARE @x INT' => 'The DECLARE keyword',
            'SELECT 1 AS n COMMIT' => 'The COMMIT keyword',
            "SELECT a.n FROM OPENROWSET('MSOLEDBSQL', 'Server=x', 'SELECT 1 AS n') AS a" => 'The OPENROWSET function',
            "SELECT a.n FROM OPENQUERY(remote, 'SELECT 1 AS n') AS a" => 'The OPENQUERY function',
        ];

        foreach ($refusedQueries as $sql => $expectedMessage) {
            $this->assertRefused(new SQLServerPlatform(), $sql, $expectedMessage);
        }
    }

    public function testRefusesTheWholeRowReferencesOfPostgresql(): void
    {
        $refusedQueries = [
            'SELECT e FROM employee e',
            'SELECT row_to_json(e) AS profile FROM employee e',
            'SELECT e::text AS row FROM employee AS e',
            'SELECT to_jsonb(employee) AS profile FROM public.employee',
            'SELECT s.name, x FROM store s JOIN employee x ON x.store_id = s.id',
            'SELECT s.name, x FROM store s, employee x',
            'SELECT x FROM store s LEFT JOIN (employee x JOIN region r ON true) ON true',
            'SELECT (SELECT json_agg(x) FROM employee x) AS employees',
            'SELECT e FROM unnest(ARRAY[1]) WITH ORDINALITY u JOIN employee e ON true',
            'SELECT "E" FROM employee "E"',
        ];

        foreach ($refusedQueries as $sql) {
            $this->assertRefused(new PostgreSQLPlatform(), $sql, 'names a table or its alias on its own');
        }

        $acceptedQueries = [
            'SELECT e.name FROM employee e',
            'SELECT employee.name FROM public.employee',
            'SELECT e.name, s.name AS store FROM employee AS e JOIN store s ON s.id = e.store_id',
            'SELECT EXTRACT(YEAR FROM e.hired_at) AS year, count(1) AS n FROM employee e GROUP BY 1',
            'SELECT substring(e.name FROM 1 FOR 3) AS initials FROM employee e',
            'WITH staff AS (SELECT e.id FROM employee e) SELECT count(1) AS n FROM staff',
            'WITH staff (id) AS (SELECT e.id FROM employee e) SELECT staff.id FROM staff',
            'SELECT s.name FROM store s WHERE s.id IS DISTINCT FROM 3',
            'SELECT g.n FROM generate_series(1, 3) AS g(n)',
            'SELECT count(1) AS s FROM store s',
            'SELECT v.n FROM (VALUES (1), (2)) AS v(n)',
        ];

        foreach ($acceptedQueries as $sql) {
            $this->assertAccepted(new PostgreSQLPlatform(), $sql);
        }
    }

    public function testRefusesTheFunctionsReachingBeyondTheDatabase(): void
    {
        $refusedQueries = [
            ['postgresql', "SELECT pg_read_file('/etc/passwd') AS content", 'The pg_read_file function'],
            ['postgresql', "SELECT \"pg_read_file\"('/etc/passwd') AS content", 'The pg_read_file function'],
            ['postgresql', "SELECT query_to_xml('SELECT 1', true, false, '') AS x", 'The query_to_xml function'],
            ['postgresql', "SELECT table_to_xml('employee', true, false, '') AS x", 'The table_to_xml function'],
            ['postgresql', "SELECT set_config('statement_timeout', '0', false) AS x", 'The set_config function'],
            ['postgresql', "SELECT d.x FROM dblink('host=x', 'SELECT 1') AS d(x int)", 'The dblink function'],
            ['sqlite', "SELECT load_extension('evil') AS x", 'The load_extension function'],
        ];

        foreach ($refusedQueries as [$engine, $sql, $expectedMessage]) {
            $this->assertRefused($this->createPlatform($engine), $sql, $expectedMessage);
        }

        // A column may bear the name of a function, as long as it is not called
        $this->assertAccepted(new PostgreSQLPlatform(), 'SELECT r.set_config FROM report r');
    }

    public function testAppliesTheSessionSettingsOfTheEngineBeforeEachQuery(): void
    {
        $expectedStatements = [
            'mysql' => ['SET SESSION max_execution_time = 10000', 'SET SESSION TRANSACTION READ ONLY'],
            'mariadb' => ['SET SESSION max_statement_time = 10.000', 'SET SESSION TRANSACTION READ ONLY'],
            'postgresql' => ['SET statement_timeout = 10000', 'SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY'],
            'sqlserver' => ['SET LOCK_TIMEOUT 10000'],
            'sqlite' => ['PRAGMA query_only = ON'],
        ];

        foreach ($expectedStatements as $engine => $expected) {
            $calls = [];
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn($this->createPlatform($engine));
            $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$calls) {
                $calls[] = $sql;

                return 0;
            });
            $connection->expects($this->once())->method('beginTransaction')->willReturnCallback(function () use (&$calls) {
                $calls[] = 'begin';
            });
            $connection->expects($this->once())->method('rollBack')->willReturnCallback(function () use (&$calls) {
                $calls[] = 'rollback';
            });
            $connection->expects($this->once())->method('executeQuery')->willReturnCallback(function () use (&$calls) {
                $calls[] = 'query';

                throw $this->createDriverException('Syntax error near "x"', 1064);
            });

            try {
                (new QueryManager($connection, $this->createSchemaManager()))->execute('SELECT x', 10);
                $this->fail('The query should have failed.');
            } catch (\InvalidArgumentException) {
            }

            // The settings apply before the query, which runs in a transaction rolled back even when it fails
            $this->assertSame([...$expected, 'begin', 'query', 'rollback'], $calls, $engine);
        }
    }

    public function testLeavesATransactionOfTheApplicationAsItIs(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->never())->method('beginTransaction');
        $connection->expects($this->never())->method('rollBack');
        $connection->method('executeQuery')->willThrowException($this->createDriverException(self::DATABASE_ERROR, 1054));

        $this->expectExceptionMessage(self::DATABASE_ERROR);
        (new QueryManager($connection, $this->createSchemaManager()))->execute('SELECT x', 10);
    }

    public function testRunsTheQueriesOnAReadOnlySqliteConnection(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE store (name VARCHAR(20))');

        $this->assertSame(['columns' => [], 'rows' => [], 'truncated' => false], (new QueryManager($connection, $this->createSchemaManager()))->execute('SELECT name FROM store', 10));
        $this->assertFalse($connection->isTransactionActive());

        $this->expectException(DBALException::class);
        $connection->executeStatement("INSERT INTO store (name) VALUES ('Written')");
    }

    public function testLimitsTheRowsAndTellsWhetherSomeWereLeftOut(): void
    {
        $queryManager = $this->createQueryManagerOnSqlite();
        $sql = 'WITH numbers AS (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3) SELECT n, n * 10 AS tenfold FROM numbers ORDER BY n';

        $result = $queryManager->execute($sql, 2);
        $this->assertSame(['n', 'tenfold'], $result['columns']);
        $this->assertSame([1, 2], array_map('intval', array_column($result['rows'], 'n')));
        $this->assertTrue($result['truncated']);

        $result = $queryManager->execute($sql, 3);
        $this->assertCount(3, $result['rows']);
        $this->assertFalse($result['truncated']);

        $result = $queryManager->execute('SELECT n FROM (SELECT 1 AS n) numbers WHERE n > 1', 10);
        $this->assertSame(['columns' => [], 'rows' => [], 'truncated' => false], $result);
    }

    public function testPassesOnTheErrorsQuotingOnlyTheQueryOrTheSchema(): void
    {
        $quotableErrors = [
            ['mysql', "Unknown column 'nme' in 'field list'", 1054, '42S22'],
            ['mysql', 'You have an error in your SQL syntax near \'FORM store\'', 1064, '42000'],
            ['mysql', "Table 'app.schol' doesn't exist", 1146, '42S02'],
            ['mysql', 'Query execution was interrupted', 3024, 'HY000'],
            ['mariadb', 'Query execution was interrupted (max_statement_time exceeded)', 1969, '70100'],
            ['postgresql', 'ERROR:  column "nme" does not exist', 7, '42703'],
            ['postgresql', 'ERROR:  canceling statement due to statement timeout', 7, '57014'],
            ['sqlserver', "Invalid column name 'nme'.", 207, '42S22'],
            ['sqlserver', 'Query timeout expired', 0, 'HYT00'],
            ['sqlite', 'SQLSTATE[HY000]: General error: 1 no such column: nme', 1, 'HY000'],
            ['sqlite', 'SQLSTATE[HY000]: General error: 1 near "FORM": syntax error', 1, 'HY000'],
        ];

        foreach ($quotableErrors as [$engine, $message, $code, $sqlState]) {
            $exception = $this->runFailingQuery($this->createPlatform($engine), $this->createDriverException($message, $code, $sqlState));
            $this->assertStringContainsString($message, $exception->getMessage(), $engine);
            $this->assertInstanceOf(DriverException::class, $exception->getPrevious());
        }
    }

    public function testHidesTheErrorsThatMayQuoteAStoredValue(): void
    {
        $leakingErrors = [
            1105 => "XPATH syntax error: '~s3cr3t-value'",
            1292 => "Truncated incorrect DOUBLE value: 's3cr3t-value'",
            1366 => "Incorrect integer value: 's3cr3t-value' for column 'n'",
            1062 => "Duplicate entry 's3cr3t-value' for key 'PRIMARY'",
        ];

        foreach ($leakingErrors as $code => $message) {
            $exception = $this->runFailingQuery(new MySQLPlatform(), $this->createDriverException($message, $code, 'HY000'));
            $this->assertStringNotContainsString('s3cr3t-value', $exception->getMessage(), (string) $code);
            $this->assertSame(sprintf('MySQL error %d (SQLSTATE HY000). Its message is hidden, since it may quote a stored value.', $code), $exception->getMessage());
        }

        $exception = $this->runFailingQuery(new MySQLPlatform(), $this->createDriverException("XPATH syntax error: '~s3cr3t-value'", 1105, null));
        $this->assertStringContainsString('SQLSTATE unknown', $exception->getMessage());

        $otherEngines = [
            ['postgresql', 'ERROR:  invalid input syntax for type integer: "s3cr3t-value"', 7, '22P02', 'PostgreSQL error (SQLSTATE 22P02)'],
            ['sqlserver', "Conversion failed when converting the varchar value 's3cr3t-value' to data type int.", 245, '22018', 'SQL Server error 245 (SQLSTATE 22018)'],
            ['sqlite', 'SQLSTATE[HY000]: General error: 19 CHECK constraint failed: s3cr3t-value', 19, 'HY000', 'SQLite error 19 (SQLSTATE HY000)'],
            ['mariadb', "Truncated incorrect DOUBLE value: 's3cr3t-value'", 1292, '22007', 'MariaDB error 1292 (SQLSTATE 22007)'],
        ];

        foreach ($otherEngines as [$engine, $message, $code, $sqlState, $expectedLabel]) {
            $exception = $this->runFailingQuery($this->createPlatform($engine), $this->createDriverException($message, $code, $sqlState));
            $this->assertSame($expectedLabel.'. Its message is hidden, since it may quote a stored value.', $exception->getMessage(), $engine);
        }
    }

    private function assertRefused(AbstractPlatform $platform, string $sql, string $expectedMessage): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects($this->never())->method('executeStatement');
        $connection->expects($this->never())->method('executeQuery');

        try {
            (new QueryManager($connection, $this->createSchemaManager()))->execute($sql, 10);
            $this->fail(sprintf('The query "%s" should have been refused.', $sql));
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage(), $sql);
        }
    }

    /**
     * Check that a query passes the checks of its dialect: it reaches the simulated database, whose error comes back, where a refusal has no previous exception.
     */
    private function assertAccepted(AbstractPlatform $platform, string $sql): void
    {
        $exception = $this->runFailingQuery($platform, $this->createDriverException(self::DATABASE_ERROR, 1054, '42S22'), $sql);
        $this->assertInstanceOf(DriverException::class, $exception->getPrevious(), $sql.': '.$exception->getMessage());
    }

    private function runFailingQuery(AbstractPlatform $platform, DriverException $driverException, string $sql = 'SELECT name FROM store'): \InvalidArgumentException
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('executeQuery')->willThrowException($driverException);

        try {
            (new QueryManager($connection, $this->createSchemaManager()))->execute($sql, 10);
        } catch (\InvalidArgumentException $exception) {
            return $exception;
        }

        $this->fail('The query should have failed.');
    }

    private function createDriverException(string $message, int $code, ?string $sqlState = '42000'): DriverException
    {
        return new DriverException(new class($message, $sqlState, $code) extends AbstractException {}, null);
    }

    private function createPlatform(string $engine): AbstractPlatform
    {
        return match ($engine) {
            'mysql' => new MySQLPlatform(),
            'mariadb' => new MariaDBPlatform(),
            'postgresql' => new PostgreSQLPlatform(),
            'sqlserver' => new SQLServerPlatform(),
            'sqlite' => DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])->getDatabasePlatform(),
        };
    }

    private function createSchemaManager(): SchemaManager
    {
        $schemaManager = $this->createStub(SchemaManager::class);
        $schemaManager->method('getForbiddenNames')->willReturn(self::FORBIDDEN_NAMES);

        return $schemaManager;
    }

    /**
     * A query manager on an in-memory SQLite database, to run the accepted queries for real.
     */
    private function createQueryManagerOnSqlite(): QueryManager
    {
        return new QueryManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $this->createSchemaManager());
    }
}
