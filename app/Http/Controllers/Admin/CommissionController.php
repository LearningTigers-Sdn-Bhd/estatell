<?php

namespace App\Http\Controllers\Admin;

use App\Data\Commissions\CommissionBookingRowData;
use App\Enums\CommissionPeriod;
use App\Enums\CommissionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CommissionBookingsRequest;
use App\Models\CommissionEntry;
use App\Models\Property;
use App\Services\Commissions\CommissionBookings;
use App\Services\Commissions\CommissionBookingsCsv;
use App\Services\Commissions\CommissionTotals;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The admin Commissions Bookings tab and its CSV. See docs/30-commission-fees/05-platform-pages.md#bookings-tab.
 */
class CommissionController extends Controller
{
    public function index(CommissionBookingsRequest $request, CommissionBookings $bookings, CommissionTotals $totals): Response
    {
        $filters = $request->filters();

        return Inertia::render('admin/commissions/index', [
            'rows' => CommissionBookingRowData::collect($bookings->paginate($filters), PaginatedDataCollection::class),
            'footer' => $bookings->totals($filters),
            'totals' => $totals->for($filters->propertyId),
            'filters' => $filters,
            'periods' => CommissionPeriod::options(),
            'services' => CommissionService::options(),
            'properties' => array_values(Property::query()
                ->whereIn('id', CommissionEntry::query()->select('property_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Property $property): array => ['value' => (string) $property->id, 'label' => $property->name])
                ->all()),
        ]);
    }

    /**
     * Every row the filters keep, as CSV, with the same columns as the page.
     */
    public function export(CommissionBookingsRequest $request, CommissionBookingsCsv $csv): BinaryFileResponse
    {
        $filters = $request->filters();

        return response()
            ->download($csv->write($filters, withProperty: true), "commissions-{$filters->from}-to-{$filters->to}.csv", ['Content-Type' => 'text/csv'])
            ->deleteFileAfterSend();
    }
}
