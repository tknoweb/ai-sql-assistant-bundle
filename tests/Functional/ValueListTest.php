<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Functional;

use Tknoweb\AiSqlAssistantBundle\Manager\JsonFlatteningManager;
use Tknoweb\AiSqlAssistantBundle\Manager\QueryManager;
use Tknoweb\AiSqlAssistantBundle\Manager\SchemaManager;
use Tknoweb\AiSqlAssistantBundle\Manager\ValueListManager;

/**
 * The values of the listable columns and JSON fields, on the database of the test application through its read-only connection, and the columns the configuration may not open.
 */
class ValueListTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $this->createDefaultStores();
    }

    public function testListsTheValuesOfAColumnAndOfAJsonField(): void
    {
        $connection = $this->getEntityManager()->getConnection();
        foreach (['Lyon', 'Lille', 'Lyon'] as $city) {
            $connection->insert('employee', ['name' => 'Ann', 'password' => 'hash', 'profile' => json_encode(['address' => ['city' => $city], 'team' => 'Sales'])]);
        }
        static::getContainer()->get(JsonFlatteningManager::class)->rebuild();

        $valueListManager = $this->createValueListManager(['store.name'], ['address[city]']);

        $this->assertSame(['store.name', 'address[city]'], $valueListManager->getSources());
        $this->assertSame(['values' => ['Alpha store', 'Beta store', 'Gamma store'], 'moreValues' => false], $valueListManager->listValues('store.name', null));
        // The text is looked for whatever its case, and a wildcard of LIKE as it is
        $this->assertSame(['values' => ['Beta store'], 'moreValues' => false], $valueListManager->listValues('store.name', 'BETA'));
        $this->assertSame(['values' => [], 'moreValues' => false], $valueListManager->listValues('store.name', '%'));
        // The values of that field only, each one once
        $this->assertSame(['values' => ['Lille', 'Lyon'], 'moreValues' => false], $valueListManager->listValues('address[city]', null));
        $this->assertSame(['values' => ['Lyon'], 'moreValues' => false], $valueListManager->listValues('address[city]', 'ly'));

        $this->assertFalse(static::getContainer()->get('doctrine.dbal.assistant_connection')->isTransactionActive(), 'The transaction of the listing must be rolled back.');
    }

    public function testRefusesAColumnTheConfigurationMayNotOpen(): void
    {
        $invalidColumns = [
            'employee.password' => 'is not a readable column of a table',
            'secret.data' => 'is not a readable column of a table',
            'conversation.title' => 'is not a readable column of a table',
            'json_value.value' => 'list a JSON field by its path in "json_paths"',
            'store.id' => 'only the values of a text column can be listed',
        ];

        foreach ($invalidColumns as $column => $expectedMessage) {
            try {
                $this->createValueListManager([$column], [])->getSources();
                $this->fail(sprintf('The column "%s" should have been refused.', $column));
            } catch (\LogicException $exception) {
                $this->assertStringContainsString($expectedMessage, $exception->getMessage(), $column);
            }
        }
    }

    private function createValueListManager(array $columns, array $jsonPaths): ValueListManager
    {
        $container = static::getContainer();

        return new ValueListManager($container->get(QueryManager::class), $container->get(SchemaManager::class), $columns, $jsonPaths);
    }
}
