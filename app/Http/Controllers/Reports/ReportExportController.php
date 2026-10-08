<?php

namespace App\Http\Controllers\Reports;

use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ExportReportRequest;
use App\Models\Property;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPage;
use App\Services\Reports\ReportPdf;
use App\Services\Reports\ReportSpreadsheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared export endpoints of every report. `{report}` is a `ReportKey`; the `can:reports.view` route middleware
 * authorizes both. The browser sends the dates, the sort and the selected row keys, never row data: the server runs
 * `rows()` again with the saved layout, so a key outside the dates or the property is dropped.
 */
class ReportExportController extends Controller
{
    /**
     * The PDF of the saved layout, shown inline.
     */
    public function pdf(ExportReportRequest $request, Property $property, ReportKey $report, ReportLayoutResolver $layoutResolver, ReportPage $reportPage, ReportPdf $reportPdf): Response
    {
        $filters = $request->filters();
        $layout = $layoutResolver->resolve($property, $report);
        $rowKeys = $request->rowKeys();
        [$rows] = $reportPage->sort($report->report()->rows($property, $filters, $rowKeys), $request->sortKey(), $report->defaultSort($filters->group), $layout->visibleKeys());

        return $reportPdf->make($property, $report, $filters, $layout, $rowKeys, $rows)->stream($reportPdf->filename($report, $filters));
    }

    /**
     * A CSV or XLSX file of the saved layout.
     */
    public function spreadsheet(ExportReportRequest $request, Property $property, ReportKey $report, ReportLayoutResolver $layoutResolver, ReportPage $reportPage, ReportSpreadsheet $reportSpreadsheet): BinaryFileResponse
    {
        $filters = $request->filters();
        $format = $request->spreadsheetFormat();
        $layout = $layoutResolver->resolve($property, $report);
        [$rows] = $reportPage->sort($report->report()->rows($property, $filters, $request->rowKeys()), $request->sortKey(), $report->defaultSort($filters->group), $layout->visibleKeys());

        return response()
            ->download($reportSpreadsheet->write($layout, $rows, $format, $report->totals($rows)), $reportSpreadsheet->filename($report, $filters, $format))
            ->deleteFileAfterSend();
    }
}
