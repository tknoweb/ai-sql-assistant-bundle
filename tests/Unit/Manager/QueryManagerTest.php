<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Manager;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;
use Tknoweb\AiSqlAssistantBundle\Manager\QueryManager;
use Tknoweb\AiSqlAssistantBundle\Manager\SchemaManager;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Doctrine\MysqlSessionStatementMiddleware;

/**
 * The checks of the queries of the model, and the filtering of the MySQL errors sent back to it: the two barriers between the model and the stored values.
 */
class QueryManagerTest extends TestCase
{
    private const FORBIDDEN_NAMES = ['secret', 'password', 'conversation', 'messenger_messages'];

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
            'SELECT name FROM store INTO OUTFILE \'/tmp/stores\'' => 'The INTO keyword is not allowed',
            'SELECT name INTO @name FROM store' => 'The INTO keyword is not allowed',
            'WITH copy AS (TABLE store) SELECT name FROM copy' => 'The TABLE keyword is not allowed',
        ];

        foreach ($refusedQueries as $sql => $expectedMessage) {
            $this->assertRefused($sql, $expectedMessage);
        }
    }

    public function testRefusesAnyForbiddenNameWhereverItIsWritten(): void
    {
        $refusedQueries = [
            'SELECT id FROM secret',
            'SELECT id FROM SECRET',
            'SELECT m.password FROM employee m',
            'SELECT id FROM `secret`',
            "SELECT id FROM store WHERE name = 'password'",
            'SELECT id FROM store -- secret',
            'SELECT id FROM store /* conversation */',
            'SELECT COUNT(*) FROM messenger_messages',
            'WITH s AS (SELECT id FROM secret) SELECT id FROM s',
        ];

        foreach ($refusedQueries as $sql) {
            $this->assertRefused($sql, 'is a forbidden table or column');
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
            'SELECT * FROM store',
            'select * from store',
            'SELECT s.* FROM store s',
            'SELECT DISTINCT * FROM store',
            'SELECT name, * FROM store',
            'SELECT (SELECT * FROM region) FROM store',
            "SELECT\n*\nFROM store",
            'SELECT SQL_NO_CACHE * FROM store',
            'WITH copy AS (SELECT * FROM store) SELECT name FROM copy',
        ];

        foreach ($refusedQueries as $sql) {
            $this->assertRefused($sql, 'Column wildcards');
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
            "SELECT LENGTH('*') AS n" => 1,
            "SELECT LENGTH('it''s * a table') AS n" => 14,
            'SELECT 1 AS n -- SELECT * FROM store' => 1,
            'SELECT 1 AS n /* SELECT * INTO */' => 1,
            'SELECT 1 AS n, 2 AS `table`' => 1,
            "SELECT LENGTH('table into') AS n" => 10,
            'WITH t AS (SELECT 4 AS n) SELECT n FROM t' => 4,
            'SELECT 5 AS n;' => 5,
            "  SELECT 5 AS n ; \n" => 5,
        ];

        foreach ($acceptedQueries as $sql => $expectedValue) {
            $this->assertSame($expectedValue, (int) $queryManager->execute($sql, 10)['rows'][0]['n'], $sql);
        }
    }

    public function testAppliesTheSessionSettingsBeforeEachQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$statements) {
            $statements[] = $sql;

            return 0;
        });
        $connection->expects($this->once())->method('executeQuery')->willReturnCallback(function () use (&$statements) {
            $this->assertSame(['SET SESSION max_execution_time = 10000', 'SET SESSION TRANSACTION READ ONLY'], $statements, 'The settings must apply before the query.');

            throw $this->createDriverException('Syntax error near "x"', 1064);
        });

        $this->expectException(\InvalidArgumentException::class);
        (new QueryManager($connection, $this->createSchemaManager()))->execute('SELECT x', 10);
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
        foreach ([1054 => "Unknown column 'nme' in 'field list'", 1064 => 'You have an error in your SQL syntax near \'FORM store\'', 1146 => "Table 'app.schol' doesn't exist", 3024 => 'Query execution was interrupted'] as $code => $message) {
            $exception = $this->runFailingQuery($this->createDriverException($message, $code, '42S22'));
            $this->assertStringContainsString($message, $exception->getMessage());
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
            $exception = $this->runFailingQuery($this->createDriverException($message, $code, 'HY000'));
            $this->assertStringNotContainsString('s3cr3t-value', $exception->getMessage(), (string) $code);
            $this->assertSame(sprintf('MySQL error %d (SQLSTATE HY000). Its message is hidden, since it may quote a stored value.', $code), $exception->getMessage());
        }

        $exception = $this->runFailingQuery($this->createDriverException("XPATH syntax error: '~s3cr3t-value'", 1105, null));
        $this->assertStringContainsString('SQLSTATE unknown', $exception->getMessage());
    }

    private function assertRefused(string $sql, string $expectedMessage): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');
        $connection->expects($this->never())->method('executeQuery');

        try {
            (new QueryManager($connection, $this->createSchemaManager()))->execute($sql, 10);
            $this->fail(sprintf('The query "%s" should have been refused.', $sql));
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage(), $sql);
        }
    }

    private function runFailingQuery(DriverException $driverException): \InvalidArgumentException
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException($driverException);

        try {
            (new QueryManager($connection, $this->createSchemaManager()))->execute('SELECT name FROM store', 10);
        } catch (\InvalidArgumentException $exception) {
            return $exception;
        }

        $this->fail('The query should have failed.');
    }

    private function createDriverException(string $message, int $code, ?string $sqlState = '42000'): DriverException
    {
        return new DriverException(new class($message, $sqlState, $code) extends AbstractException {}, null);
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
        $configuration = new Configuration();
        $configuration->setMiddlewares([new MysqlSessionStatementMiddleware()]);

        return new QueryManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $this->createSchemaManager());
    }
}
