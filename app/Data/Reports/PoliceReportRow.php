<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use App\Models\Guest;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Police report: one guest attached to a reservation unit, or the booker of a unit with no attached
 * guest. The property names are the column keys of `ReportKey::PoliceReport`. `key` is `{unit id}:{guest id}`.
 * `nationality` and the country in `address` are country names. `identity` has its number already masked or full for
 * the user who asked (see `GuestIdentities`). `checked_in_at` and `checked_out_at` are text in the property time zone.
 */
class PoliceReportRow extends Data implements ReportRow
{
    public function __construct(
        public string $key,
        public string $guest,
        public string $reservation,
        public ?string $room,
        public string $nationality,
        public string $gender,
        public ?string $date_of_birth,
        public string $identity,
        public string $address,
        public string $phone,
        public string $arrival,
        public string $checked_in_at,
        public string $departure,
        public string $checked_out_at,
        public BadgeData $status,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'guest' => $this->guest,
            'reservation' => $this->reservation,
            'room' => $this->room ?? '',
            'nationality' => $this->nationality,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth === null ? '' : CarbonImmutable::parse($this->date_of_birth)->format('j M Y'),
            'identity' => $this->identity,
            'address' => $this->address,
            'phone' => $this->phone,
            'arrival' => CarbonImmutable::parse($this->arrival)->format('j M Y'),
            'checked_in_at' => $this->checked_in_at,
            'departure' => CarbonImmutable::parse($this->departure)->format('j M Y'),
            'checked_out_at' => $this->checked_out_at,
            'status' => $this->status->label,
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'guest' => $this->guest,
            'reservation' => $this->reservation,
            'room' => $this->room ?? '',
            'nationality' => $this->nationality,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth ?? '',
            'identity' => $this->identity,
            'address' => $this->address,
            'phone' => $this->phone,
            'arrival' => $this->arrival,
            'checked_in_at' => $this->checked_in_at,
            'departure' => $this->departure,
            'checked_out_at' => $this->checked_out_at,
            'status' => $this->status->label,
        ];
    }

    /**
     * @param  string  $nationality  The country name of the guest's nationality, or an empty string.
     * @param  string  $address  The guest's address on one line.
     */
    public static function fromGuest(ReservationUnit $unit, Guest $guest, ?string $room, string $nationality, string $identity, string $address, string $checkedIn, string $checkedOut): self
    {
        return new self(
            key: $unit->id.':'.$guest->id,
            guest: $guest->full_name,
            reservation: $unit->reservation->reference,
            room: $room,
            nationality: $nationality,
            gender: $guest->gender?->label() ?? '',
            date_of_birth: $guest->date_of_birth?->toDateString(),
            identity: $identity,
            address: $address,
            phone: $guest->phone ?? '',
            arrival: $unit->arrival_date->toDateString(),
            checked_in_at: $checkedIn,
            departure: $unit->departure_date->toDateString(),
            checked_out_at: $checkedOut,
            status: BadgeData::fromUnitStatus($unit->status),
        );
    }
}
