<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Functional;

use Tknoweb\AiSqlAssistantBundle\Manager\JsonCatalogManager;

/**
 * The search of the catalog of the JSON paths by the model: ranking, groups and limits, on the catalog table of the test application.
 */
class JsonCatalogSearchTest extends FunctionalTestCase
{
    private JsonCatalogManager $catalogManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $this->catalogManager = static::getContainer()->get(JsonCatalogManager::class);
    }

    public function testRanksThePathsMatchingTheMostWordsFirst(): void
    {
        $this->insertPaths([
            ['document', 'content', 'form', 'v2', '3-part-price-median-*', '{"3-part-price-median-*":"Median price of the products"}'],
            ['document', 'content', 'form', 'v2', '3-part-price-mean-*', null],
            ['document', 'content', 'form', 'v1', '3-part-median-*', null],
            ['employee', 'profile', null, null, 'address[city]', null],
        ]);

        $result = $this->catalogManager->search('Medians discounts');

        // "medians" loses its plural mark and matches, "discounts" matches no path
        $this->assertSame(['results' => [
            ['source' => 'document.content', 'document_type' => 'form', 'template' => 'v2', 'generic_paths' => ['3-part-price-median-*'], 'truncated' => false],
            ['source' => 'document.content', 'document_type' => 'form', 'template' => 'v1', 'generic_paths' => ['3-part-median-*'], 'truncated' => false],
        ], 'labels' => ['3-part-price-median-*' => 'Median price of the products'], 'truncated' => false], $result);

        // The path matching both words first, then the latest template for the same score
        $result = $this->catalogManager->search('median price');
        $this->assertSame(['3-part-price-median-*', '3-part-price-mean-*'], $result['results'][0]['generic_paths']);
        $this->assertSame(['3-part-median-*'], $result['results'][1]['generic_paths']);
        $this->assertCount(2, $result['results']);

        // A key found by its label only, and a group without document type nor template
        $this->assertSame(['3-part-price-median-*'], $this->catalogManager->search('products')['results'][0]['generic_paths']);
        $this->assertSame([['source' => 'employee.profile', 'generic_paths' => ['address[city]'], 'truncated' => false]], $this->catalogManager->search('city')['results']);
        $this->assertSame(['results' => [], 'labels' => [], 'truncated' => false], $this->catalogManager->search('nothing'));
    }

    public function testCutsTheGroupsThatWouldHideTheOthers(): void
    {
        $paths = [];
        for ($index = 1; $index <= 12; ++$index) {
            $paths[] = ['document', 'content', 'report', 'v1', sprintf('rate-%02d', $index), null];
        }
        $paths[] = ['document', 'content', 'form', 'v1', 'rate', null];
        $this->insertPaths($paths);

        $result = $this->catalogManager->search('rate');

        $this->assertCount(2, $result['results']);
        $groups = array_column($result['results'], null, 'document_type');
        $this->assertCount(8, $groups['report']['generic_paths']);
        $this->assertTrue($groups['report']['truncated']);
        $this->assertSame(['rate'], $groups['form']['generic_paths']);
        $this->assertFalse($groups['form']['truncated']);
        $this->assertFalse($result['truncated']);
    }

    public function testReturnsTwentyFivePathsAtMost(): void
    {
        $paths = [];
        for ($index = 1; $index <= 30; ++$index) {
            $paths[] = ['document', 'content', 'report', sprintf('v%02d', $index), 'rate', null];
        }
        $this->insertPaths($paths);

        $result = $this->catalogManager->search('rate');

        $this->assertCount(25, $result['results']);
        $this->assertTrue($result['truncated']);
        $this->assertSame('v30', $result['results'][0]['template']);
    }

    public function testSearchesAWordLiterally(): void
    {
        $this->insertPaths([['employee', 'profile', null, null, 'rate_x', null], ['employee', 'profile', null, null, 'ratex', null], ['employee', 'profile', null, null, 'rate%', null]]);

        $this->assertSame(['rate_x'], $this->catalogManager->search('rate_x')['results'][0]['generic_paths']);
        $this->assertSame(['rate%'], $this->catalogManager->search('rate%')['results'][0]['generic_paths']);
        $this->assertSame(['results' => [], 'labels' => [], 'truncated' => false], $this->catalogManager->search('ra!e'));
    }

    private function insertPaths(array $paths): void
    {
        $connection = $this->getEntityManager()->getConnection();
        foreach ($paths as [$sourceTable, $sourceColumn, $documentType, $template, $genericPath, $labels]) {
            $connection->insert('json_path', [
                'source_table' => $sourceTable,
                'source_column' => $sourceColumn,
                'document_type' => $documentType,
                'template' => $template,
                'generic_path' => $genericPath,
                'labels' => $labels,
            ]);
        }
    }
}
