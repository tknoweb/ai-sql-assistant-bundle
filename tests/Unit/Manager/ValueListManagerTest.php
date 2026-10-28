<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Tknoweb\AiSqlAssistantBundle\Manager\QueryManager;
use Tknoweb\AiSqlAssistantBundle\Manager\SchemaManager;
use Tknoweb\AiSqlAssistantBundle\Manager\ValueListManager;

/**
 * The values of the listable columns and JSON fields given to the model: which sources may be listed, how they are read, and how much of them the model gets. The database is a test double.
 */
class ValueListManagerTest extends TestCase
{
    private const TABLES = [
        'store' => ['description' => null, 'columns' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'notes' => ['type' => 'text']]],
        'json_value' => ['description' => null, 'columns' => ['generic_path' => ['type' => 'string'], 'value' => ['type' => 'text']]],
    ];

    public function testListsNothingByDefault(): void
    {
        $this->assertSame([], (new ValueListManager($this->createStub(QueryManager::class), $this->createSchemaManager()))->getSources());
    }

    public function testListsTheValuesOfAColumnOrOfAJsonField(): void
    {
        $queryManager = $this->createMock(QueryManager::class);
        $queryManager->expects($this->exactly(2))->method('fetchDistinctValues')->willReturnCallback(fn (string $table, string $column, array $where, ?string $text, int $maxValues): array => match ([$table, $column, $where, $text, $maxValues]) {
            ['store', 'name', [], 'alp', 101] => ['Alpha store'],
            ['json_value', 'value', ['generic_path' => 'payload[data][status]'], null, 101] => ['closed', 'open'],
        });
        $valueListManager = new ValueListManager($queryManager, $this->createSchemaManager(), ['store.name', 'store.notes'], ['payload[data][status]', 'store.name']);

        // In the order of the configuration, each source once
        $this->assertSame(['store.name', 'store.notes', 'payload[data][status]'], $valueListManager->getSources());
        $this->assertSame(['values' => ['Alpha store'], 'moreValues' => false], $valueListManager->listValues('store.name', 'alp'));
        $this->assertSame(['values' => ['closed', 'open'], 'moreValues' => false], $valueListManager->listValues('payload[data][status]', null));
    }

    public function testCutsTheLongValuesAndTellsWhetherSomeWereLeftOut(): void
    {
        $values = [str_repeat('é', 150), ...array_map(fn (int $index) => sprintf('Store %03d', $index), range(1, 100))];
        $queryManager = $this->createStub(QueryManager::class);
        $queryManager->method('fetchDistinctValues')->willReturn($values);

        $result = (new ValueListManager($queryManager, $this->createSchemaManager(), ['store.notes']))->listValues('store.notes', null);

        $this->assertCount(ValueListManager::MAX_VALUES, $result['values']);
        $this->assertTrue($result['moreValues']);
        $this->assertSame(str_repeat('é', ValueListManager::MAX_VALUE_LENGTH).'…', $result['values'][0]);
        $this->assertSame('Store 099', end($result['values']));
    }

    public function testRefusesASourceTheConfigurationDoesNotList(): void
    {
        $queryManager = $this->createMock(QueryManager::class);
        $queryManager->expects($this->never())->method('fetchDistinctValues');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"store.id" is not a listable column or field');
        (new ValueListManager($queryManager, $this->createSchemaManager(), ['store.name']))->listValues('store.id', null);
    }

    public function testRefusesAColumnThatCannotBeListed(): void
    {
        $invalidColumns = [
            // A forbidden column is unknown to the schema manager, like a missing one
            'store.password' => 'is not a readable column of a table',
            'missing.name' => 'is not a readable column of a table',
            'store' => 'is not a readable column of a table',
            // The view of the flattened values would give the values of every field
            'json_value.value' => 'list a JSON field by its path in "json_paths"',
            'store.id' => 'is of type "integer": only the values of a text column can be listed',
        ];

        foreach ($invalidColumns as $column => $expectedMessage) {
            try {
                (new ValueListManager($this->createStub(QueryManager::class), $this->createSchemaManager(), [$column]))->getSources();
                $this->fail(sprintf('The column "%s" should have been refused.', $column));
            } catch (\LogicException $exception) {
                $this->assertStringContainsString($expectedMessage, $exception->getMessage(), $column);
            }
        }
    }

    private function createSchemaManager(): SchemaManager
    {
        $schemaManager = $this->createStub(SchemaManager::class);
        $schemaManager->method('getTables')->willReturn(self::TABLES);
        $schemaManager->method('getJsonValueViewName')->willReturn('json_value');

        return $schemaManager;
    }
}
