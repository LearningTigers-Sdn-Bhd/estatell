<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use App\Models\Guest;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One row of the Police report: one guest attached to a reservation unit, or the booker of a unit with no attached
 * guest. The property names are the column keys of `ReportKey::PoliceReport`. `key` is `{unit id}:{guest id}`.
 * `nationality` and the country in `address` are country names. `identity` has its number already masked or full for
 * the user who asked (see `GuestIdentities`). `checked_in_at` and `checked_out_at` are text in the property time zone, and the `_date` and `_time` columns split them.
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
        public string $id_type,
        public string $id_number,
        public string $address,
        public string $phone,
        public string $arrival,
        public string $arrival_time,
        public string $checked_in_at,
        public string $checked_in_date,
        public string $checked_in_time,
        public string $departure,
        public string $departure_time,
        public string $checked_out_at,
        public string $checked_out_date,
        public string $checked_out_time,
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
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'address' => $this->address,
            'phone' => $this->phone,
            'arrival' => CarbonImmutable::parse($this->arrival)->format('j M Y'),
            'arrival_time' => $this->arrival_time,
            'checked_in_at' => $this->checked_in_at,
            'checked_in_date' => $this->checked_in_date,
            'checked_in_time' => $this->checked_in_time,
            'departure' => CarbonImmutable::parse($this->departure)->format('j M Y'),
            'departure_time' => $this->departure_time,
            'checked_out_at' => $this->checked_out_at,
            'checked_out_date' => $this->checked_out_date,
            'checked_out_time' => $this->checked_out_time,
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
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'address' => $this->address,
            'phone' => $this->phone,
            'arrival' => $this->arrival,
            'arrival_time' => $this->arrival_time,
            'checked_in_at' => $this->checked_in_at,
            'checked_in_date' => $this->checked_in_date,
            'checked_in_time' => $this->checked_in_time,
            'departure' => $this->departure,
            'departure_time' => $this->departure_time,
            'checked_out_at' => $this->checked_out_at,
            'checked_out_date' => $this->checked_out_date,
            'checked_out_time' => $this->checked_out_time,
            'status' => $this->status->label,
        ];
    }

    /**
     * @param  string  $nationality  The country name of the guest's nationality, or an empty string.
     * @param  string  $address  The guest's address on one line.
     * @param  string  $timeZone  The property time zone the check-in and check-out times are shown in.
     */
    public static function fromGuest(ReservationUnit $unit, Guest $guest, ?string $room, string $nationality, string $identity, string $idType, string $idNumber, string $address, ?CarbonInterface $checkedIn, ?CarbonInterface $checkedOut, string $timeZone): self
    {
        $local = fn (?CarbonInterface $moment): ?CarbonInterface => $moment?->copy()->setTimezone($timeZone);
        $format = fn (?CarbonInterface $moment, string $pattern): string => $local($moment)?->format($pattern) ?? '';

        return new self(
            key: $unit->id.':'.$guest->id,
            guest: $guest->full_name,
            reservation: $unit->reservation->reference,
            room: $room,
            nationality: $nationality,
            gender: $guest->gender?->label() ?? '',
            date_of_birth: $guest->date_of_birth?->toDateString(),
            identity: $identity,
            id_type: $idType,
            id_number: $idNumber,
            address: $address,
            phone: $guest->phone ?? '',
            arrival: $unit->arrival_date->toDateString(),
            arrival_time: substr($unit->arrival_time, 0, 5),
            checked_in_at: $format($checkedIn, 'j M Y H:i'),
            checked_in_date: $format($checkedIn, 'j M Y'),
            checked_in_time: $format($checkedIn, 'H:i'),
            departure: $unit->departure_date->toDateString(),
            departure_time: substr($unit->departure_time, 0, 5),
            checked_out_at: $format($checkedOut, 'j M Y H:i'),
            checked_out_date: $format($checkedOut, 'j M Y'),
            checked_out_time: $format($checkedOut, 'H:i'),
            status: BadgeData::fromUnitStatus($unit->status),
        );
    }
}
