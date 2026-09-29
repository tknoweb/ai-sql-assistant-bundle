<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Functional;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Doctrine\MysqlSessionStatementMiddleware;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\Store;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\ScriptedModelProvider;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\StoreStatus;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\TestKernel;

/**
 * Tests running the bundle in the test application, on a database rebuilt empty for each test.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected static function isMysql(): bool
    {
        return static::getContainer()->get('doctrine.dbal.default_connection')->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    /**
     * Drop every table of the test database and create the schema of the test application. A MySQL database must be named "*_test", so that no other one is ever emptied by mistake.
     */
    protected function resetDatabase(): void
    {
        $entityManager = $this->getEntityManager();
        $connection = $entityManager->getConnection();
        if (static::isMysql() && !str_ends_with((string) $connection->getDatabase(), '_test')) {
            $this->fail(sprintf('The MySQL database of %s must be named "*_test", since all of its tables are dropped.', TestKernel::DATABASE_URL_VARIABLE));
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        MysqlSessionStatementMiddleware::$skippedStatements = [];
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    protected function getProvider(): ScriptedModelProvider
    {
        return static::getContainer()->get(ScriptedModelProvider::class);
    }

    protected function getUser(string $identifier): InMemoryUser
    {
        return new InMemoryUser($identifier, $identifier, 'carol' === $identifier ? ['ROLE_USER'] : ['ROLE_ASSISTANT']);
    }

    protected function createStores(array $stores): void
    {
        $entityManager = $this->getEntityManager();
        foreach ($stores as $name => $status) {
            $entityManager->persist(new Store($name, $status));
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function createDefaultStores(): void
    {
        $this->createStores(['Alpha store' => StoreStatus::Open, 'Beta store' => StoreStatus::Closed, 'Gamma store' => StoreStatus::Open]);
    }
}
