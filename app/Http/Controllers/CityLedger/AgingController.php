<?php

namespace App\Http\Controllers\CityLedger;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\CityLedger\AgingReport;
use App\Services\CityLedger\ArInvoiceDirectory;
use App\Services\Property\PrintedPdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The aging report page and its CSV and PDF. The `as_at` date defaults to the business date and is never in the
 * future. See docs/15-city-ledger/04-credit-control-and-reports.md#aging-report.
 */
class AgingController extends Controller
{
    public function index(Request $request, Property $property, AgingReport $agingReport, ArInvoiceDirectory $directory): Response
    {
        $asAt = $this->asAt($request, $property, $directory);

        return Inertia::render('finance/city-ledger/aging/index', [
            'report' => $agingReport->build($property, $asAt),
            'today' => $directory->today($property),
            'currency' => $property->settings?->currency,
        ]);
    }

    /**
     * One row for each company and a totals row, streamed.
     */
    public function csv(Request $request, Property $property, AgingReport $agingReport, ArInvoiceDirectory $directory): StreamedResponse
    {
        $report = $agingReport->build($property, $this->asAt($request, $property, $directory));

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [__('Company'), __('Current'), '1-30', '31-60', '61-90', __('Over 90'), __('Credit'), __('Total')]);

            foreach ([...$report->rows, $report->totals] as $row) {
                fputcsv($out, [$row->companyName, $row->current, $row->days1to30, $row->days31to60, $row->days61to90, $row->over90, $row->credit, $row->total]);
            }

            fclose($out);
        }, 'city-ledger-aging-'.$report->asAt.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function pdf(Request $request, Property $property, AgingReport $agingReport, ArInvoiceDirectory $directory, PrintedPdf $printedPdf): HttpResponse
    {
        $report = $agingReport->build($property, $this->asAt($request, $property, $directory));

        return $printedPdf->make($property, 'pdf.ar-aging', [
            'report' => $report,
            'currency' => $property->settings?->currency->value ?? '',
        ], 'landscape')
            ->stream('city-ledger-aging-'.$report->asAt.'.pdf');
    }

    private function asAt(Request $request, Property $property, ArInvoiceDirectory $directory): CarbonImmutable
    {
        $today = CarbonImmutable::parse($directory->today($property));
        $given = $request->date('as_at', 'Y-m-d');

        return $given === null || $given->toImmutable()->startOfDay()->gt($today) ? $today : $given->toImmutable()->startOfDay();
    }
}
