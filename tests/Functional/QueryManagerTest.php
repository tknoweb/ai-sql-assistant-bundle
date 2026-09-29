<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Functional;

use Tknoweb\AiSqlAssistantBundle\Manager\QueryManager;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Doctrine\MysqlSessionStatementMiddleware;

/**
 * The queries of the model on the database of the test application, through the connection set in the "connection" configuration of the bundle.
 */
class QueryManagerTest extends FunctionalTestCase
{
    private QueryManager $queryManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $this->createDefaultStores();
        $this->queryManager = static::getContainer()->get(QueryManager::class);
    }

    public function testRunsAQueryOnTheQueryConnection(): void
    {
        $result = $this->queryManager->execute("SELECT name, status AS store_status FROM store WHERE status = 'open' ORDER BY name", 10);

        $this->assertSame(['name', 'store_status'], $result['columns']);
        $this->assertSame([['name' => 'Alpha store', 'store_status' => 'open'], ['name' => 'Gamma store', 'store_status' => 'open']], $result['rows']);
        $this->assertFalse($result['truncated']);

        if (!static::isMysql()) {
            $this->assertSame(['SET SESSION max_execution_time = 10000', 'SET SESSION TRANSACTION READ ONLY'], MysqlSessionStatementMiddleware::$skippedStatements);
        }
    }

    public function testRefusesTheForbiddenNamesOfTheMapping(): void
    {
        $refusedQueries = [
            'SELECT recoveryCode FROM employee' => '"recoveryCode" is a forbidden table or column',
            'SELECT password FROM employee' => '"password" is a forbidden table or column',
            'SELECT id FROM secret' => '"secret" is a forbidden table or column',
            'SELECT title FROM conversation' => '"conversation" is a forbidden table or column',
            'SELECT user_input FROM conversation_exchange' => '"conversation_exchange" is a forbidden table or column',
        ];

        foreach ($refusedQueries as $sql => $expectedMessage) {
            try {
                $this->queryManager->execute($sql, 10);
                $this->fail(sprintf('The query "%s" should have been refused.', $sql));
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString($expectedMessage, $exception->getMessage());
            }
        }
    }

    public function testRefusesToWriteOnMysql(): void
    {
        if (!static::isMysql()) {
            $this->markTestSkipped('The read only session is a MySQL setting.');
        }

        // The session of the query connection is left read only, which refuses a write even to a user allowed to write, as the test one is
        $this->queryManager->execute('SELECT name FROM store', 10);
        $this->expectException(\Doctrine\DBAL\Exception::class);
        static::getContainer()->get('doctrine.dbal.assistant_connection')->executeStatement("INSERT INTO region (name) VALUES ('Written')");
    }
}
