<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Functional;

use Tknoweb\AiSqlAssistantBundle\Manager\JsonFlatteningManager;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\TestKernel;

/**
 * The flattening on a real MySQL database: the copies are created, filled and swapped with the live tables, which a query of the model then reads. Skipped on SQLite, which has neither
 * CREATE TABLE ... LIKE nor a multiple RENAME TABLE (the logic itself is covered by Unit\Manager\JsonFlatteningManagerTest).
 */
class JsonFlatteningTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        if (!static::isMysql()) {
            $this->markTestSkipped(sprintf('The flattening swaps MySQL tables: set %s to a MySQL test database to run it.', TestKernel::DATABASE_URL_VARIABLE));
        }

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

        $this->assertSame(
            [['document', '3-part-price-median-*', '45', '30000.000000'], ['employee', 'address[city]', null, null]],
            $connection->fetchAllNumeric("SELECT source_table, generic_path, CAST(path_id1 AS CHAR), CAST(number_value AS CHAR) FROM json_value WHERE generic_path IN ('3-part-price-median-*', 'address[city]') ORDER BY source_table")
        );
        $this->assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM json_value WHERE value = 'never flattened'"));
        $this->assertSame(['3-part-price-median-*', 'address[city]', 'template'], $connection->fetchFirstColumn('SELECT generic_path FROM json_path ORDER BY generic_path'));
        $this->assertSame([], $connection->fetchFirstColumn("SHOW TABLES LIKE 'json\\_%\\_new'"));
        $this->assertSame([], $connection->fetchFirstColumn("SHOW TABLES LIKE 'json\\_%\\_old'"));
    }
}
