<?php

namespace App\Http\Controllers\Transfers;

use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\ExportTransferManifestRequest;
use App\Models\Property;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPdf;
use App\Services\Reports\ReportSpreadsheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Transfer manifest of the run sheet as a PDF or a spreadsheet. Front desk staff do not need `reports.view`, so these
 * routes call the same services as `ReportExportController` behind `reservations.view`. The browser sends the date, never
 * row data: the server reads the rows again in pick-up order with the saved layout.
 * See docs/35-transfers/01-land-transfers.md#transfer-manifest.
 */
class TransferExportController extends Controller
{
    public function pdf(ExportTransferManifestRequest $request, Property $property, ReportLayoutResolver $layoutResolver, ReportPdf $reportPdf): Response
    {
        $report = ReportKey::TransferManifest;
        $filters = $request->filters();
        $rowKeys = $request->rowKeys();

        return $reportPdf
            ->make($property, $report, $filters, $layoutResolver->resolve($property, $report), $rowKeys, $report->report()->rows($property, $filters, $rowKeys))
            ->stream($reportPdf->filename($report, $filters));
    }

    public function spreadsheet(ExportTransferManifestRequest $request, Property $property, ReportLayoutResolver $layoutResolver, ReportSpreadsheet $reportSpreadsheet): BinaryFileResponse
    {
        $report = ReportKey::TransferManifest;
        $filters = $request->filters();
        $format = $request->spreadsheetFormat();
        $rows = $report->report()->rows($property, $filters, $request->rowKeys());

        return response()
            ->download($reportSpreadsheet->write($layoutResolver->resolve($property, $report), $rows, $format, $report->totals($rows)), $reportSpreadsheet->filename($report, $filters, $format))
            ->deleteFileAfterSend();
    }
}
