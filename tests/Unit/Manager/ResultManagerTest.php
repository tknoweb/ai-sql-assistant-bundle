<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Manager;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\UX\Chartjs\Builder\ChartBuilder;
use Symfony\UX\Chartjs\Model\Chart;
use Tknoweb\AiSqlAssistantBundle\Manager\ResultManager;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\StoreStatus;

/**
 * The shaping of a query result for the user: headers, labels of the coded columns, chart and Excel file.
 */
class ResultManagerTest extends TestCase
{
    private const PALETTE = ['#111111', '#222222'];

    public function testLabelsTheColumns(): void
    {
        $resultManager = $this->createResultManager();

        $this->assertSame('Status of the store', $resultManager->getColumnLabel('store_status'));
        $this->assertSame('Store count', $resultManager->getColumnLabel('store_count'));
        $this->assertSame('Total', $resultManager->getColumnLabel(' total_ '));
        $this->assertSame('Year', $resultManager->getColumnLabel('year'));
    }

    public function testLabelsTheCodesOfTheCodedColumns(): void
    {
        $rows = $this->createResultManager()->getLabelledRows(['columns' => ['name', 'store_status'], 'rows' => [
            ['name' => 'open', 'store_status' => 'open'],
            ['name' => 'Two', 'store_status' => 'open,closed'],
            ['name' => 'Three', 'store_status' => null],
        ]]);

        $this->assertSame([
            ['name' => 'open', 'store_status' => 'Open store'],
            ['name' => 'Two', 'store_status' => 'Open store, Closed store'],
            ['name' => 'Three', 'store_status' => null],
        ], $rows);
    }

    public function testDrawsOneSeriesPerValueColumn(): void
    {
        $rows = [['year' => 2024, 'stores' => '3', 'employees' => 5], ['year' => 2025, 'stores' => 4, 'employees' => 'n/a']];

        $chart = $this->createResultManager()->createChart($rows, ['type' => 'line', 'labelColumn' => 'year', 'valueColumns' => ['stores', 'employees']]);

        $this->assertSame(Chart::TYPE_LINE, $chart->getType());
        $this->assertSame([
            'labels' => ['2024', '2025'],
            'datasets' => [
                ['label' => 'Stores', 'backgroundColor' => '#111111', 'borderColor' => '#111111', 'data' => [3.0, 4.0]],
                ['label' => 'Employees', 'backgroundColor' => '#222222', 'borderColor' => '#222222', 'data' => [5.0, null]],
            ],
        ], $chart->getData());
        // The options of the configuration come on top of the default ones
        $this->assertSame(['plugins' => ['legend' => ['position' => 'bottom'], 'datalabels' => ['display' => true]]], $chart->getOptions());
    }

    public function testColorsEachSliceOfAPieChart(): void
    {
        $rows = [['status' => 'Open', 'n' => 3], ['status' => 'Closed', 'n' => 1], ['status' => 'Merged', 'n' => 2]];

        $chart = $this->createResultManager()->createChart($rows, ['type' => 'pie', 'labelColumn' => 'status', 'valueColumns' => ['n', 'other']]);

        $this->assertSame(Chart::TYPE_PIE, $chart->getType());
        $this->assertCount(1, $chart->getData()['datasets'], 'A pie chart draws a single series.');
        $this->assertSame(['#111111', '#222222', '#111111'], $chart->getData()['datasets'][0]['backgroundColor']);
        $this->assertSame(Chart::TYPE_BAR, $this->createResultManager()->createChart($rows, ['type' => 'bar', 'labelColumn' => 'status', 'valueColumns' => ['n']])->getType());
    }

    public function testExportsAnExcelFileThatRunsNoFormula(): void
    {
        $response = $this->createResultManager()->createSpreadsheetResponse(
            ['name', 'store_status', 'n'],
            [['name' => '=HYPERLINK("http://evil.test","Click")', 'store_status' => 'Open store', 'n' => 3], ['name' => 'Plain', 'store_status' => null, 'n' => 4.5]],
            'Stores by status'
        );

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertSame(sprintf('attachment; filename=stores-by-status-%s.xlsx', date('Y-m-d')), $response->headers->get('Content-Disposition'));

        $worksheet = $this->readSpreadsheet($response)->getActiveSheet();
        $this->assertSame(['Name', 'Status of the store', 'N'], [$worksheet->getCell('A1')->getValue(), $worksheet->getCell('B1')->getValue(), $worksheet->getCell('C1')->getValue()]);
        $this->assertTrue($worksheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('=HYPERLINK("http://evil.test","Click")', $worksheet->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $worksheet->getCell('A2')->getDataType(), 'A text starting with "=" must stay a text.');
        $this->assertSame(4.5, $worksheet->getCell('C3')->getValue());
    }

    public function testNamesAnUntitledExportAfterItsDate(): void
    {
        $response = $this->createResultManager()->createSpreadsheetResponse([], [], '  ');

        $this->assertSame(sprintf('attachment; filename=export-%s.xlsx', date('Y-m-d')), $response->headers->get('Content-Disposition'));
    }

    private function readSpreadsheet(StreamedResponse $response): Spreadsheet
    {
        ob_start();
        $response->sendContent();
        $file = tempnam(sys_get_temp_dir(), 'ai_sql_assistant_test_');
        file_put_contents($file, ob_get_clean());

        try {
            return (new Xlsx())->load($file);
        } finally {
            unlink($file);
        }
    }

    private function createResultManager(): ResultManager
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['open' => 'Open store', 'closed' => 'Closed store', 'columnStoreStatus' => 'Status of the store'], 'en', 'messages');

        return new ResultManager(
            $translator,
            new ChartBuilder(),
            new AsciiSlugger(),
            ['store_status' => StoreStatus::class],
            'messages',
            'column',
            self::PALETTE,
            ['plugins' => ['datalabels' => ['display' => true]]],
        );
    }
}
