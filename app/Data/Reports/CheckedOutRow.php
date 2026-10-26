<?php

namespace App\Data\Reports;

use App\Models\ReservationUnit;
use App\Services\Reservations\StayLabel;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Checked out report. The property names are the column keys of `ReportKey::CheckedOut`.
 * `key` is the reservation unit id. `checked_out_at` is `Y-m-d H:i` in the property time zone, so it sorts as text.
 */
class CheckedOutRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $reservation,
        public string $guest,
        public ?string $phone,
        public string $departure,
        public string $checked_out_at,
        public int $nights,
        public ?string $stay_label,
        public string $unit,
        public string $room_type,
        public ?string $room,
        public string $guests,
        public int $adults,
        public int $children,
        public ?string $company,
        public string $balance,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'reservation' => $this->reservation,
            'guest' => $this->guest,
            'phone' => $this->phone ?? '',
            'departure' => CarbonImmutable::parse($this->departure)->format('j M Y'),
            'checked_out_at' => $this->checked_out_at === '' ? '' : CarbonImmutable::parse($this->checked_out_at)->format('j M Y H:i'),
            'nights' => $this->stay_label ?? (string) $this->nights,
            'unit' => $this->unit,
            'room_type' => $this->room_type,
            'room' => $this->room ?? '',
            'guests' => $this->guests,
            'adults' => (string) $this->adults,
            'children' => (string) $this->children,
            'company' => $this->company ?? '',
            'balance' => number_format((float) $this->balance, 2),
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'reservation' => $this->reservation,
            'guest' => $this->guest,
            'phone' => $this->phone ?? '',
            'departure' => $this->departure,
            'checked_out_at' => $this->checked_out_at,
            'nights' => $this->stay_label ?? $this->nights,
            'unit' => $this->unit,
            'room_type' => $this->room_type,
            'room' => $this->room ?? '',
            'guests' => $this->guests,
            'adults' => $this->adults,
            'children' => $this->children,
            'company' => $this->company ?? '',
            'balance' => round((float) $this->balance, 2),
        ];
    }

    /**
     * @param  string|null  $room  The room number or bed label of the last night, when one is assigned.
     * @param  string  $timeZone  The property time zone the check-out time is shown in.
     */
    public static function fromModel(ReservationUnit $unit, ?string $room, string $balance, string $timeZone): self
    {
        $reservation = $unit->reservation;
        $occupant = ($unit->guests->firstWhere('is_primary', true) ?? $unit->guests->first())->guest ?? $reservation->booker;

        return new self(
            key: $unit->id,
            reservation: $reservation->reference,
            guest: $occupant->full_name,
            phone: $occupant->phone,
            departure: $unit->departure_date->toDateString(),
            checked_out_at: $unit->checked_out_at?->copy()->setTimezone($timeZone)->format('Y-m-d H:i') ?? '',
            nights: (int) $unit->arrival_date->diffInDays($unit->departure_date),
            stay_label: app(StayLabel::class)->hoursForUnit($unit),
            unit: $room === null ? $unit->roomType->name : "{$unit->roomType->name} / {$room}",
            room_type: $unit->roomType->name,
            room: $room,
            guests: "{$unit->adults} / {$unit->children}",
            adults: $unit->adults,
            children: $unit->children,
            company: $reservation->company?->name,
            balance: $balance,
        );
    }
}
