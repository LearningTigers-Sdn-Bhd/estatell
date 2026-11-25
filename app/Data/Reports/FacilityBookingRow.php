<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Facility bookings report: one booking. The property names are the column keys of
 * `ReportKey::FacilityBookings`. `key` is the booking id, `date` is `Y-m-d` and `time` is "20:00–22:00". `owner` is the
 * event name or the reservation's booker, `ref` the event number, or the reservation's rooms (its reference when no
 * room is set). `amount` is the price before tax, null when charged by hand. `section` groups the rows of one facility,
 * date and time, and `capacity` and `unit` give its subtotal.
 */
class FacilityBookingRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $date,
        public string $time,
        public string $facility,
        public string $owner,
        public string $ref,
        public int $quantity,
        public BadgeData $status,
        public ?string $phone,
        public string $folio,
        public ?string $amount,
        public ?string $notes,
        public ?string $dietary,
        public string $section,
        public int $capacity,
        public string $unit,
        public bool $counts,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'date' => CarbonImmutable::parse($this->date)->format('j M Y'),
            'time' => $this->time,
            'facility' => $this->facility,
            'owner' => $this->owner,
            'ref' => $this->ref,
            'quantity' => (string) $this->quantity,
            'status' => $this->status->label,
            'phone' => $this->phone ?? '',
            'folio' => $this->folio,
            'amount' => $this->amount === null ? '' : number_format((float) $this->amount, 2),
            'notes' => $this->notes ?? '',
            'dietary' => $this->dietary ?? '',
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'date' => $this->date,
            'time' => $this->time,
            'facility' => $this->facility,
            'owner' => $this->owner,
            'ref' => $this->ref,
            'quantity' => $this->quantity,
            'status' => $this->status->label,
            'phone' => $this->phone ?? '',
            'folio' => $this->folio,
            'amount' => $this->amount === null ? '' : round((float) $this->amount, 2),
            'notes' => $this->notes ?? '',
            'dietary' => $this->dietary ?? '',
        ];
    }
}
