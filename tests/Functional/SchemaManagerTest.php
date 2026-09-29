<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Functional;

use Tknoweb\AiSqlAssistantBundle\Manager\SchemaManager;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\Document;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\Employee;

/**
 * The description of the database sent to the model, from the Doctrine mapping of the test application, and what it keeps out.
 */
class SchemaManagerTest extends FunctionalTestCase
{
    private SchemaManager $schemaManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->schemaManager = static::getContainer()->get(SchemaManager::class);
    }

    public function testForbidsTheConfiguredNamesAndTheConversations(): void
    {
        $this->assertEqualsCanonicalizing(
            ['messenger_messages', 'conversation', 'conversation_exchange', 'secret', 'password', 'recoveryCode'],
            $this->schemaManager->getForbiddenNames()
        );
    }

    public function testListsTheReadableTablesWithTheirDescription(): void
    {
        $tables = $this->schemaManager->getTables();

        $this->assertSame(['audit_log', 'document', 'employee', 'json_path', 'json_value', 'region', 'setting', 'store', 'store_region'], array_keys($tables));
        $this->assertSame('Store of the retail chain.', $tables['store']['description'], 'Only the first paragraph of the docblock.');
        $this->assertNull($tables['region']['description'], 'A docblock saying "Region entity." tells nothing.');
        $this->assertNull($tables['audit_log']['description']);
        $this->assertSame('Link table of a many-to-many association.', $tables['store_region']['description']);

        $tableList = $this->schemaManager->getTableList();
        $this->assertStringContainsString('- `store`: Store of the retail chain.', $tableList);
        $this->assertStringContainsString("\n- `region`\n", $tableList);
        foreach (['`conversation', '`secret`', 'messenger'] as $forbiddenName) {
            $this->assertStringNotContainsString($forbiddenName, $tableList);
        }
    }

    public function testDescribesTheColumnsWithoutTheForbiddenOnes(): void
    {
        $tables = $this->schemaManager->getTables();

        $this->assertSame(['type' => 'string', 'codes' => ['open', 'closed']], $tables['store']['columns']['status']);
        $this->assertSame(['type' => 'simple_array', 'nullable' => true], $tables['store']['columns']['tags']);
        $this->assertSame(['type' => 'integer', 'nullable' => true, 'references' => 'store.id'], $tables['employee']['columns']['store_id']);
        $this->assertSame(['type' => 'string', 'codes' => ['report', 'form']], $tables['document']['columns']['type']);
        $this->assertEqualsCanonicalizing(['id', 'name', 'store_id', 'profile'], array_keys($tables['employee']['columns']));
        $this->assertEqualsCanonicalizing(['store_id', 'region_id'], array_keys($tables['store_region']['columns']));

        $description = $this->schemaManager->describeTables(['store', 'secret', 'unknown', 'store']);
        $this->assertStringContainsString("## store\n\n- `id` integer\n", $description);
        $this->assertStringContainsString('- `status` string, codes: open, closed', $description);
        $this->assertStringContainsString('- `tags` simple_array, nullable (comma separated values)', $description);
        $this->assertSame(1, substr_count($description, '## store'));
        // A forbidden table is reported as an unknown one
        $this->assertStringContainsString("## secret\n\nUnknown table: check the list of the database tables.", $description);
        $this->assertStringContainsString("## unknown\n\nUnknown table: check the list of the database tables.", $description);

        $this->assertStringContainsString('- `store_id` integer, nullable, references store.id', $this->schemaManager->describeTables(['employee']));
        $this->assertStringNotContainsString('password', $this->schemaManager->describeTables(['employee']));
    }

    public function testListsTheJsonColumnsToFlatten(): void
    {
        $jsonColumns = $this->schemaManager->getJsonColumns();
        usort($jsonColumns, fn (array $first, array $second) => $first['table'] <=> $second['table']);

        // Neither the forbidden tables, nor the unflattened entities, nor a table without an "id" identifier
        $this->assertSame([
            ['table' => 'document', 'column' => 'content', 'discriminatorColumn' => 'type', 'entityClass' => Document::class],
            ['table' => 'employee', 'column' => 'profile', 'discriminatorColumn' => null, 'entityClass' => Employee::class],
        ], $jsonColumns);
        $this->assertSame('json_value', $this->schemaManager->getJsonValueTableName());
    }
}
