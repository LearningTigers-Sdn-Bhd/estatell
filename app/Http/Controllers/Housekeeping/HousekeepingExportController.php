<?php

namespace App\Http\Controllers\Housekeeping;

use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Housekeeping\ExportHousekeepingRequest;
use App\Models\Property;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPage;
use App\Services\Reports\ReportPdf;
use App\Services\Reports\ReportSpreadsheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The print sheet and the spreadsheet of the board. Housekeeping staff do not have `reports.view`, so these routes
 * call the same services as `ReportExportController` behind `housekeeping.view`. The browser sends the date, the
 * sort and the room ids, never row data: the server reads the rooms again with the saved layout.
 */
class HousekeepingExportController extends Controller
{
    public function pdf(ExportHousekeepingRequest $request, Property $property, ReportLayoutResolver $layoutResolver, ReportPage $reportPage, ReportPdf $reportPdf): Response
    {
        $report = ReportKey::HousekeepingTasks;
        $filters = $request->filters();
        $layout = $layoutResolver->resolve($property, $report);
        $rowKeys = $request->rowKeys();
        [$rows] = $reportPage->sort($report->report()->rows($property, $filters, $rowKeys), $request->sortKey(), $report->defaultSort(), $layout->visibleKeys());

        return $reportPdf->make($property, $report, $filters, $layout, $rowKeys, $rows)->stream($reportPdf->filename($report, $filters));
    }

    public function spreadsheet(ExportHousekeepingRequest $request, Property $property, ReportLayoutResolver $layoutResolver, ReportPage $reportPage, ReportSpreadsheet $reportSpreadsheet): BinaryFileResponse
    {
        $report = ReportKey::HousekeepingTasks;
        $filters = $request->filters();
        $format = $request->spreadsheetFormat();
        $layout = $layoutResolver->resolve($property, $report);
        [$rows] = $reportPage->sort($report->report()->rows($property, $filters, $request->rowKeys()), $request->sortKey(), $report->defaultSort(), $layout->visibleKeys());

        return response()
            ->download($reportSpreadsheet->write($layout, $rows, $format, null), $reportSpreadsheet->filename($report, $filters, $format))
            ->deleteFileAfterSend();
    }
}
