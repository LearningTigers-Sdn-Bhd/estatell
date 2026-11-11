<?php

namespace App\Http\Controllers\Payouts;

use App\Data\Commissions\CommissionBookingRowData;
use App\Enums\CommissionPeriod;
use App\Enums\CommissionService;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payouts\PayoutBookingsRequest;
use App\Models\Property;
use App\Services\Commissions\CommissionBookings;
use App\Services\Commissions\CommissionBookingsCsv;
use App\Services\Commissions\PropertyPayouts;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The property Payouts Bookings tab and its CSV. See docs/30-commission-fees/06-property-payouts.md#bookings-tab.
 */
class PayoutBookingController extends Controller
{
    public function index(PayoutBookingsRequest $request, Property $property, CommissionBookings $bookings, PropertyPayouts $payouts): Response
    {
        $filters = $request->filters($property);

        return Inertia::render('finance/payouts/index', [
            'rows' => CommissionBookingRowData::collect($bookings->paginate($filters), PaginatedDataCollection::class),
            'footer' => $bookings->totals($filters),
            'summary' => $payouts->summary($property),
            'filters' => $filters,
            'periods' => CommissionPeriod::options(),
            'services' => CommissionService::options(),
            'canOpenReservations' => $request->user()->can(PermissionEnum::ReservationsView->value),
        ]);
    }

    /**
     * Every row the filters keep, as CSV, without the Property column.
     */
    public function export(PayoutBookingsRequest $request, Property $property, CommissionBookingsCsv $csv): BinaryFileResponse
    {
        $filters = $request->filters($property);

        return response()
            ->download($csv->write($filters, withProperty: false), "payouts-{$filters->from}-to-{$filters->to}.csv", ['Content-Type' => 'text/csv'])
            ->deleteFileAfterSend();
    }
}
