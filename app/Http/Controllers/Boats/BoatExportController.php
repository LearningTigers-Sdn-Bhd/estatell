<?php

namespace App\Http\Controllers\Boats;

use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Boats\ExportBoatManifestRequest;
use App\Models\Property;
use App\Services\Reports\BoatManifestReport;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPage;
use App\Services\Reports\ReportPdf;
use App\Services\Reports\ReportSpreadsheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The manifest sheet and the spreadsheet of the Boat Trips board. Front desk staff do not need `reports.view`, so these
 * routes call the same services as `ReportExportController` behind `reservations.view`. The browser sends the date, the
 * direction and the ticked trip ids, never row data: the server reads the rows again with the saved layout. The
 * spreadsheet adds the boat jobs after the guest rows. See
 * docs/24-boats/05-boats-board.md#export-boat-manifest.
 */
class BoatExportController extends Controller
{
    public function pdf(ExportBoatManifestRequest $request, Property $property, ReportLayoutResolver $layoutResolver, ReportPage $reportPage, ReportPdf $reportPdf): Response
    {
        $report = ReportKey::BoatManifest;
        $filters = $request->filters();
        $layout = $layoutResolver->resolve($property, $report);
        $rowKeys = $request->rowKeys();
        [$rows] = $reportPage->sort($report->report()->rows($property, $filters, $rowKeys), $request->sortKey(), $report->defaultSort(), $layout->visibleKeys());

        return $reportPdf->make($property, $report, $filters, $layout, $rowKeys, $rows)->stream($reportPdf->filename($report, $filters));
    }

    public function spreadsheet(ExportBoatManifestRequest $request, Property $property, ReportLayoutResolver $layoutResolver, ReportPage $reportPage, ReportSpreadsheet $reportSpreadsheet, BoatManifestReport $manifest): BinaryFileResponse
    {
        $report = ReportKey::BoatManifest;
        $filters = $request->filters();
        $format = $request->spreadsheetFormat();
        $layout = $layoutResolver->resolve($property, $report);
        [$rows] = $reportPage->sort($manifest->rows($property, $filters, $request->rowKeys()), $request->sortKey(), $report->defaultSort(), $layout->visibleKeys());
        $rows = [...$rows, ...$manifest->jobRows($property, $filters, $request->rowKeys())];

        return response()
            ->download($reportSpreadsheet->write($layout, $rows, $format, null), $reportSpreadsheet->filename($report, $filters, $format))
            ->deleteFileAfterSend();
    }
}
