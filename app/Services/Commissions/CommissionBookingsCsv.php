<?php

namespace App\Services\Commissions;

use App\Data\Commissions\CommissionBookingFilters;
use App\Data\Commissions\CommissionBookingRowData;
use App\Enums\CommissionService;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * The CSV of a Commissions or Payouts Bookings tab: every row the filters keep, with the same columns as the page.
 */
class CommissionBookingsCsv
{
    public function __construct(private CommissionBookings $bookings) {}

    /**
     * Writes the CSV to a temporary file and returns its path. The property page leaves out the Property column.
     */
    public function write(CommissionBookingFilters $filters, bool $withProperty): string
    {
        $path = sys_get_temp_dir().'/'.Str::uuid().'.csv';
        $writer = SimpleExcelWriter::create($path);

        foreach ($this->bookings->all($filters) as $row) {
            $writer->addRow($this->row($row, $withProperty));
        }

        $writer->close();

        return $path;
    }

    /**
     * @return array<string, string|null>
     */
    private function row(CommissionBookingRowData $row, bool $withProperty): array
    {
        $commissions = collect(CommissionService::cases())->mapWithKeys(fn (CommissionService $service): array => [
            __(':service commission', ['service' => $service->label()]) => $row->commissions[$service->value] ?? '0.00',
        ])->all();

        return [
            ...($withProperty ? [__('Property') => $row->propertyName] : []),
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
