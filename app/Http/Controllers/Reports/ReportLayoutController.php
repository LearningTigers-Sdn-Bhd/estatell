<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\LogIdentityExport;
use App\Actions\Reports\ResetReportLayout;
use App\Actions\Reports\UpdateReportLayout;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\PreviewReportLayoutRequest;
use App\Http\Requests\Reports\UpdateReportLayoutRequest;
use App\Models\Property;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared layout endpoints of every report. `{report}` is a `ReportKey`; the `can:settings.manage` route
 * middleware authorizes all three.
 */
class ReportLayoutController extends Controller
{
    public function update(UpdateReportLayoutRequest $request, Property $property, ReportKey $report, UpdateReportLayout $updateReportLayout): RedirectResponse
    {
        $updateReportLayout($request->user(), $property, $report, $request->orientation(), $request->layoutColumns());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Layout saved.')]);

        return back();
    }

    public function destroy(Request $request, Property $property, ReportKey $report, ResetReportLayout $resetReportLayout): RedirectResponse
    {
        $resetReportLayout($request->user(), $property, $report);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Layout reset to the default.')]);

        return back();
    }

    /**
     * The PDF of the dates in the request with the unsaved layout, shown inline. The rows are read again on the server.
     */
    public function preview(PreviewReportLayoutRequest $request, Property $property, ReportKey $report, ReportLayoutResolver $resolver, ReportPdf $reportPdf, LogIdentityExport $logIdentityExport): Response
    {
        $filters = $request->filters();
        $layout = $resolver->fromDraft($report, $request->orientation(), $request->layoutColumns());

        $rows = $report->report()->rows($property, $filters);
        $logIdentityExport($request->user(), $property, $report, $filters, $layout, $rows, null);

        return $reportPdf->make($property, $report, $filters, $layout, null, $rows)->stream($reportPdf->filename($report, $filters));
    }
}
