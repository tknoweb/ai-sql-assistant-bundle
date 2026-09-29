<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

use function Symfony\Component\String\u;

/**
 * Shapes the result of a query of the assistant for the user who asked for it: column headers, labels of the coded columns, chart and Excel file.
 * These values are only ever displayed to that user, never sent to the model.
 */
class ResultManager
{
    // Rows of an Excel export, far above the display limit but still bounded, the query having to run again for the export
    public const MAX_EXPORTED_ROWS = 100000;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ChartBuilderInterface $chartBuilder,
        private readonly SluggerInterface $slugger,
        #[Autowire('%tknoweb_ai_sql_assistant.coded_columns%')]
        private readonly array $codedColumns,
        #[Autowire('%tknoweb_ai_sql_assistant.coded_values_translation_domain%')]
        private readonly string $codedValuesTranslationDomain,
        #[Autowire('%tknoweb_ai_sql_assistant.column_label_translation_prefix%')]
        private readonly string $columnLabelTranslationPrefix,
        #[Autowire('%tknoweb_ai_sql_assistant.chart.palette%')]
        private readonly array $chartPalette,
        #[Autowire('%tknoweb_ai_sql_assistant.chart.options%')]
        private readonly array $chartOptions,
    ) {
    }

    /**
     * Header of a result column: the label of a coded column, which keeps its name, or the alias the model gave the other ones, made readable.
     */
    public function getColumnLabel(string $column): string
    {
        // The key of a coded column is its name in camel case after the prefix, e.g. "columnCourseRoute" for "course_route" with the prefix "column"
        if (isset($this->codedColumns[$column])) {
            return $this->translator->trans($this->columnLabelTranslationPrefix.u($column)->camel()->title(), domain: $this->codedValuesTranslationDomain);
        }

        return u($column)->replace('_', ' ')->trim()->title()->toString();
    }

    /**
     * Rows of a result with the codes of the coded columns replaced by their label. A comma separated list of codes is labelled code by code.
     */
    public function getLabelledRows(array $result): array
    {
        return array_map(function (array $row) {
            foreach ($row as $column => $value) {
                if (null !== $value && isset($this->codedColumns[$column])) {
                    $row[$column] = implode(', ', array_map(fn (string $code) => $this->translator->trans(trim($code), domain: $this->codedValuesTranslationDomain), explode(',', (string) $value)));
                }
            }

            return $row;
        }, $result['rows']);
    }

    /**
     * Chart.js chart of labelled rows, as described by the model and checked by AssistantManager: one series per value column, a single one for a pie chart.
     */
    public function createChart(array $rows, array $chart): Chart
    {
        $type = match ($chart['type']) {
            'line' => Chart::TYPE_LINE,
            'pie' => Chart::TYPE_PIE,
            default => Chart::TYPE_BAR,
        };
        $valueColumns = Chart::TYPE_PIE === $type ? array_slice($chart['valueColumns'], 0, 1) : $chart['valueColumns'];
        $palette = $this->chartPalette;

        $datasets = [];
        foreach ($valueColumns as $index => $column) {
            $color = $palette[$index % count($palette)];
            $datasets[] = [
                'label' => $this->getColumnLabel($column),
                // A pie chart colors each slice, the other ones each series
                'backgroundColor' => Chart::TYPE_PIE === $type
                    ? array_map(fn (int $rowIndex) => $palette[$rowIndex % count($palette)], array_keys($rows))
                    : $color,
                'borderColor' => $color,
                'data' => array_map(fn (array $row) => is_numeric($row[$column]) ? (float) $row[$column] : null, $rows),
            ];
        }

        return $this->chartBuilder->createChart($type)
            ->setData([
                'labels' => array_map(fn (array $row) => (string) $row[$chart['labelColumn']], $rows),
                'datasets' => $datasets,
            ])
            // The options of the "chart" configuration come on top, to write the values next to each point with the datalabels plugin, for instance
            ->setOptions(array_replace_recursive([
                'plugins' => [
                    'legend' => [
                        'position' => 'bottom',
                    ],
                ],
            ], $this->chartOptions));
    }

    /**
     * Excel file of labelled rows, named after the title the model gave the result.
     */
    public function createSpreadsheetResponse(array $columns, array $rows, string $title): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();

        foreach ($columns as $columnIndex => $column) {
            $worksheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex + 1).'1', $this->getColumnLabel($column));
        }

        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 2);
                // A text starting with "=" would otherwise be written as a formula
                if (is_string($value) && str_starts_with($value, '=')) {
                    $worksheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
                } else {
                    $worksheet->setCellValue($coordinate, $value);
                }
            }
        }

        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($columns)));
        $worksheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        foreach (array_keys($columns) as $columnIndex) {
            $worksheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex + 1))->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $response = new StreamedResponse(fn () => $writer->save('php://output'));

        $fileName = $this->slugger->slug('' !== trim($title) ? $title : 'export')->lower().'-'.date('Y-m-d').'.xlsx';
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName));

        return $response;
    }
}
