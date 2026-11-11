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
use App\Services\Commissions\CommissionTotals;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\SimpleExcel\SimpleExcelWriter;
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
    public function export(CommissionBookingsRequest $request, CommissionBookings $bookings): BinaryFileResponse
    {
        $filters = $request->filters();
        $path = sys_get_temp_dir().'/'.Str::uuid().'.csv';
        $writer = SimpleExcelWriter::create($path);

        foreach ($bookings->all($filters) as $row) {
            $writer->addRow($this->csvRow($row));
        }

        $writer->close();

        return response()
            ->download($path, "commissions-{$filters->from}-to-{$filters->to}.csv", ['Content-Type' => 'text/csv'])
            ->deleteFileAfterSend();
    }

    /**
     * @return array<string, string|null>
     */
    private function csvRow(CommissionBookingRowData $row): array
    {
        $commissions = collect(CommissionService::cases())->mapWithKeys(fn (CommissionService $service): array => [
            __(':service commission', ['service' => $service->label()]) => $row->commissions[$service->value] ?? '0.00',
        ])->all();

        return [
            __('Property') => $row->propertyName,
            __('Booking') => $row->bookingReference,
            __('Source') => $row->sourceName,
            __('Booked') => $row->bookedAt,
            __('Check-in') => $row->checkIn,
            __('Check-out') => $row->checkOut,
            __('Guest') => $row->guestName,
            __('Value') => $row->value,
            ...$commissions,
            __('Outstanding to hotel') => $row->outstanding,
        ];
    }
}
