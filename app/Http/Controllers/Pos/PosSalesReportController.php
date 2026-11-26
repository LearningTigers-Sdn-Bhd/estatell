<?php

namespace App\Http\Controllers\Pos;

use App\Data\Property\CurrencyData;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Pos\PosSalesReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Walk-in and room-charged POS sales for a date range: by outlet and by category, with voids, discounts, redeemed
 * included meals, and staff time-clock records to audit.
 */
class PosSalesReportController extends Controller
{
    public function __invoke(Request $request, Property $property, PosSalesReport $report): Response
    {
        $property->loadMissing('settings');
        $dates = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $from = $dates['from'] ?? now()->startOfMonth()->toDateString();
        $to = $dates['to'] ?? now()->toDateString();

        return Inertia::render('reports/pos-sales', [
            'currency' => CurrencyData::forProperty($property),
            'filters' => ['from' => $from, 'to' => $to],
            ...$report->forRange($property, $from, $to),
        ]);
    }
}
