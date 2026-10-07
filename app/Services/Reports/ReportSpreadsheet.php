<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportLayoutColumnData;
use App\Data\Reports\ReportLayoutData;
use App\Data\Reports\ReportRow;
use App\Enums\ReportKey;
use App\Enums\SpreadsheetFormat;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Writes the rows of a report to a CSV or XLSX file: a header row of the visible column labels in layout order, then
 * one row per report row with its typed `values()` (money and counts are numbers).
 */
class ReportSpreadsheet
{
    /**
     * Write the file to a temporary path and return it. The caller deletes it after sending.
     *
     * @param  list<ReportRow>  $rows
     */
    public function write(ReportLayoutData $layout, array $rows, SpreadsheetFormat $format): string
    {
        $columns = $layout->visibleColumns();
        $path = sys_get_temp_dir().'/'.Str::uuid().'.'.$format->value;

        $writer = SimpleExcelWriter::create($path)
            ->addHeader(array_map(fn (ReportLayoutColumnData $column): string => $column->label, $columns));

        foreach ($rows as $row) {
            $values = $row->values();

            $writer->addRow(array_map(fn (ReportLayoutColumnData $column): int|float|string => $values[$column->key] ?? '', $columns));
        }

        $writer->close();

        return $path;
    }

    public function filename(ReportKey $report, ReportFilters $filters, SpreadsheetFormat $format): string
    {
        return "{$report->value}-{$filters->from}".($filters->to === $filters->from ? '' : "-{$filters->to}").".{$format->value}";
    }
}
