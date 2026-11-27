<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use App\Enums\BookingChangeKind;
use App\Models\ReservationUnit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of Rooms on books › Changes: one change to one room or bed booking. The property names are the column keys
 * of `ReportKey::BookingChanges`. `previous_arrival` and `previous_departure` are set on an amendment only.
 * `nights` and `revenue` are the stay after the change (the booked price of its nights, before discounts and tax);
 * `nights_change` and `revenue_change` are what the change added (negative for a cancellation or no-show).
 * See docs/22-reports/05-rooms-on-books.md.
 */
class BookingChangeRow extends Data implements ReportRow
{
    public function __construct(
        public string $key,
        public BadgeData $kind,
        public string $changed_at,
        public string $reservation,
        public int $reservation_id,
        public string $guest,
        public string $room,
        public ?string $previous_arrival,
        public ?string $previous_departure,
        public string $arrival,
        public string $departure,
        public int $nights,
        public string $rate,
        public string $revenue,
        public int $nights_change,
        public string $revenue_change,
        public string $rate_plan,
        public bool $complimentary,
        public ?string $group,
        public string $source,
        public ?string $booked_by,
        public ?string $reason,
    ) {}

    /**
     * Expects the unit's `reservation.booker`, `reservation.creator`, `reservation.bookingSource`, `roomType`,
     * `ratePlan` and `guests.guest`.
     *
     * @param  array{key: string, kind: BookingChangeKind, at: CarbonImmutable, arrival: string, departure: string, previous_arrival: ?string, previous_departure: ?string, revenue: string, revenue_change: string, nights_change: int, reason: ?string}  $change
     * @param  string|null  $room  The room number or bed label of the first night, when one is assigned.
     */
    public static function fromChange(array $change, ReservationUnit $unit, ?string $room): self
    {
        $reservation = $unit->reservation;
        $occupant = ($unit->guests->firstWhere('is_primary', true) ?? $unit->guests->first())->guest ?? $reservation->booker;
        $nights = (int) CarbonImmutable::parse($change['arrival'])->diffInDays(CarbonImmutable::parse($change['departure']));
        $revenue = BigDecimal::of($change['revenue']);

        return new self(
            key: $change['key'],
            kind: BadgeData::fromBookingChangeKind($change['kind']),
            changed_at: $change['at']->format('Y-m-d H:i'),
            reservation: $reservation->reference,
            reservation_id: $reservation->id,
            guest: $occupant->full_name,
            room: $room === null ? $unit->roomType->name : "{$unit->roomType->name} / {$room}",
            previous_arrival: $change['previous_arrival'],
            previous_departure: $change['previous_departure'],
            arrival: $change['arrival'],
            departure: $change['departure'],
            nights: $nights,
            rate: (string) ($nights === 0 ? $revenue->toScale(2, RoundingMode::HalfUp) : $revenue->dividedBy($nights, 2, RoundingMode::HalfUp)),
            revenue: (string) $revenue->toScale(2, RoundingMode::HalfUp),
            nights_change: $change['nights_change'],
            revenue_change: (string) BigDecimal::of($change['revenue_change'])->toScale(2, RoundingMode::HalfUp),
            rate_plan: $unit->ratePlan->name,
            complimentary: $unit->ratePlan->is_complimentary,
            group: $reservation->group_name,
            source: $reservation->bookingSource->name,
            booked_by: $reservation->creator?->name,
            reason: $change['reason'],
        );
    }

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'kind' => $this->kind->label,
            'changed_at' => CarbonImmutable::parse($this->changed_at)->format('j M Y H:i'),
            'reservation' => $this->reservation,
            'guest' => $this->guest,
            'room' => $this->room,
            'previous_arrival' => $this->date($this->previous_arrival),
            'previous_departure' => $this->date($this->previous_departure),
            'arrival' => $this->date($this->arrival),
            'departure' => $this->date($this->departure),
            'nights' => (string) $this->nights,
            'rate' => number_format((float) $this->rate, 2),
            'revenue' => number_format((float) $this->revenue, 2),
            'nights_change' => $this->signed((string) $this->nights_change, 0),
            'revenue_change' => $this->signed($this->revenue_change, 2),
            'rate_plan' => $this->complimentary ? $this->rate_plan.' ('.__('Complimentary').')' : $this->rate_plan,
            'group' => $this->group ?? '',
            'source' => $this->source,
            'booked_by' => $this->booked_by ?? '',
            'reason' => $this->reason ?? '',
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'kind' => $this->kind->label,
            'changed_at' => $this->changed_at,
            'reservation' => $this->reservation,
            'guest' => $this->guest,
            'room' => $this->room,
            'previous_arrival' => $this->previous_arrival ?? '',
            'previous_departure' => $this->previous_departure ?? '',
            'arrival' => $this->arrival,
            'departure' => $this->departure,
            'nights' => $this->nights,
            'rate' => round((float) $this->rate, 2),
            'revenue' => round((float) $this->revenue, 2),
            'nights_change' => $this->nights_change,
            'revenue_change' => round((float) $this->revenue_change, 2),
            'rate_plan' => $this->rate_plan,
            'group' => $this->group ?? '',
            'source' => $this->source,
            'booked_by' => $this->booked_by ?? '',
            'reason' => $this->reason ?? '',
        ];
    }

    private function date(?string $date): string
    {
        return $date === null ? '—' : CarbonImmutable::parse($date)->format('j M Y');
    }

    private function signed(string $value, int $decimals): string
    {
        $number = (float) $value;

        return ($number > 0 ? '+' : '').number_format($number, $decimals);
    }
}
