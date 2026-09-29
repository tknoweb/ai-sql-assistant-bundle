<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Functional;

use Tknoweb\AiSqlAssistantBundle\Manager\JsonFlatteningManager;

/**
 * The flattening on the database of the test application: the copies are created, filled and swapped with the live tables, as the engine of the database does it, and a query of the model
 * then reads them.
 */
class JsonFlatteningTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    public function testRebuildsTheFlatTablesInPlace(): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->insert('document', ['type' => 'form', 'content' => json_encode(['template' => 'v2', '3-part-price-median-45' => '30 000', '3-part-ignore-previous' => 'x'])]);
        $connection->insert('employee', ['name' => 'Ann', 'password' => 'hash', 'profile' => json_encode(['address' => ['city' => 'Lyon']])]);
        $connection->insert('secret', ['data' => json_encode(['token' => 'never flattened'])]);
        $flatteningManager = static::getContainer()->get(JsonFlatteningManager::class);

        // Twice, the second run swapping the tables the first one built
        foreach ([1, 2] as $run) {
            $this->assertSame(['values' => 4, 'paths' => 3, 'skippedValues' => 0, 'skippedRows' => 0], $flatteningManager->rebuild(), 'Run '.$run);
        }

        $values = $connection->fetchAllAssociative("SELECT source_table, generic_path, path_id1, number_value FROM json_value WHERE generic_path IN ('3-part-price-median-*', 'address[city]') ORDER BY source_table");
        $this->assertSame(
            [['document', '3-part-price-median-*', 45, 30000.0], ['employee', 'address[city]', null, null]],
            array_map(fn (array $row) => [$row['source_table'], $row['generic_path'], null !== $row['path_id1'] ? (int) $row['path_id1'] : null, null !== $row['number_value'] ? (float) $row['number_value'] : null], $values)
        );
        $this->assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM json_value WHERE value = 'never flattened'"));
        $this->assertSame(['3-part-price-median-*', 'address[city]', 'template'], $connection->fetchFirstColumn('SELECT generic_path FROM json_path ORDER BY generic_path'));

        // The values are those of the last run only, and no copy is left
        $this->assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM json_value'));
        foreach (['json_value_new', 'json_path_new', 'json_value_old', 'json_path_old'] as $copy) {
            $this->assertFalse($connection->createSchemaManager()->tablesExist([$copy]), $copy);
        }
    }
}
