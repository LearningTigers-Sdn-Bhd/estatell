<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use App\Enums\RemarkKind;
use App\Models\ReservationRemark;
use App\Models\ReservationUnit;
use App\Services\Meals\MealPlanLabel;
use App\Services\Reservations\StayLabel;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Arrivals report. The property names are the column keys of `ReportKey::Arrivals`.
 * `key` is the reservation unit id.
 */
class ArrivalRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $reservation,
        public string $guest,
        public ?string $phone,
        public string $arrival,
        public string $arrival_time,
        public ?string $boat,
        public int $nights,
        public ?string $stay_label,
        public string $unit,
        public string $room_type,
        public ?string $room,
        public string $guests,
        public int $adults,
        public int $children,
        public ?string $company,
        public BadgeData $status,
        public string $total,
        public string $paid,
        public string $balance,
        public string $departure,
        public string $departure_time,
        public string $rate,
        public string $rate_plan,
        public ?string $meal_plan,
        public string $source,
        public ?string $market_segment,
        public ?string $sales_person,
        public ?string $booked_by,
        public ?string $housekeeping_remarks,
        public ?string $dietary_remarks,
        public ?string $package_remarks,
        public ?string $payment_remarks,
        public ?string $pick_up = null,
        public ?string $drop_off = null,
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
            'arrival' => CarbonImmutable::parse($this->arrival)->format('j M Y'),
            'arrival_time' => $this->arrival_time,
            'boat' => $this->boat ?? '',
            'nights' => $this->stay_label ?? (string) $this->nights,
            'unit' => $this->unit,
            'room_type' => $this->room_type,
            'room' => $this->room ?? '',
            'guests' => $this->guests,
            'adults' => (string) $this->adults,
            'children' => (string) $this->children,
            'company' => $this->company ?? '',
            'status' => $this->status->label,
            'total' => number_format((float) $this->total, 2),
            'paid' => number_format((float) $this->paid, 2),
            'balance' => number_format((float) $this->balance, 2),
            'departure' => CarbonImmutable::parse($this->departure)->format('j M Y'),
            'departure_time' => $this->departure_time,
            'rate' => number_format((float) $this->rate, 2),
            ...$this->textCells(),
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
            'arrival' => $this->arrival,
            'arrival_time' => $this->arrival_time,
            'boat' => $this->boat ?? '',
            'nights' => $this->stay_label ?? $this->nights,
            'unit' => $this->unit,
            'room_type' => $this->room_type,
            'room' => $this->room ?? '',
            'guests' => $this->guests,
            'adults' => $this->adults,
            'children' => $this->children,
            'company' => $this->company ?? '',
            'status' => $this->status->label,
            'total' => round((float) $this->total, 2),
            'paid' => round((float) $this->paid, 2),
            'balance' => round((float) $this->balance, 2),
            'departure' => $this->departure,
            'departure_time' => $this->departure_time,
            'rate' => round((float) $this->rate, 2),
            ...$this->textCells(),
        ];
    }

    /**
     * The columns that are the same plain text on screen, in the PDF and in the spreadsheet.
     *
     * @return array<string, string>
     */
    private function textCells(): array
    {
        return [
            'rate_plan' => $this->rate_plan,
            'meal_plan' => $this->meal_plan ?? '',
            'source' => $this->source,
            'market_segment' => $this->market_segment ?? '',
            'sales_person' => $this->sales_person ?? '',
            'booked_by' => $this->booked_by ?? '',
            'housekeeping_remarks' => $this->housekeeping_remarks ?? '',
            'dietary_remarks' => $this->dietary_remarks ?? '',
            'package_remarks' => $this->package_remarks ?? '',
            'payment_remarks' => $this->payment_remarks ?? '',
            'pick_up' => $this->pick_up ?? '',
            'drop_off' => $this->drop_off ?? '',
        ];
    }

    /**
     * Expects the unit's `nights_sum_amount` and `nights_count` (`withSum` / `withCount` of `nights`), its
     * `ratePlan.inclusions.mealPeriod`, and the reservation's `bookingSource`, `creator`, `salesPerson`, `marketSegment`
     * and `remarks`. **Rate** is the average room price per night (`unit_nights.amount`, before tax).
     *
     * @param  string|null  $boat  The boat text, or null while the Boats feature is off.
     * @param  string|null  $pickUp  The road transfer in (`TransferLabel`), null without one or while Transfers is off.
     * @param  string|null  $dropOff  The road transfer out, likewise.
     * @param  string|null  $room  The room number or bed label of the first night, when one is assigned.
     * @param  array{total: string, paid: string, balance: string}  $money  The total, paid and balance of the reservation.
     */
    public static function fromModel(ReservationUnit $unit, ?string $room, array $money, ?string $boat = null, ?string $pickUp = null, ?string $dropOff = null): self
    {
        $reservation = $unit->reservation;
        $remarks = $reservation->remarks->mapWithKeys(fn (ReservationRemark $remark): array => [$remark->kind->value => $remark->body]);
        $nightCount = (int) $unit->getAttribute('nights_count');
        $nightsAmount = BigDecimal::of((string) ($unit->getAttribute('nights_sum_amount') ?? '0'));
        $occupant = ($unit->guests->firstWhere('is_primary', true) ?? $unit->guests->first())->guest ?? $reservation->booker;

        return new self(
            key: $unit->id,
            reservation: $reservation->reference,
            guest: $occupant->full_name,
            phone: $occupant->phone,
            arrival: $unit->arrival_date->toDateString(),
            arrival_time: substr($unit->arrival_time, 0, 5),
            boat: $boat,
            nights: (int) $unit->arrival_date->diffInDays($unit->departure_date),
            stay_label: app(StayLabel::class)->hoursForUnit($unit),
            unit: $room === null ? $unit->roomType->name : "{$unit->roomType->name} / {$room}",
            room_type: $unit->roomType->name,
            room: $room,
            guests: "{$unit->adults} / {$unit->children}",
            adults: $unit->adults,
            children: $unit->children,
            company: $reservation->company?->name,
            status: BadgeData::fromUnitStatus($unit->status),
            total: $money['total'],
            paid: $money['paid'],
            balance: $money['balance'],
            departure: $unit->departure_date->toDateString(),
            departure_time: substr($unit->departure_time, 0, 5),
            rate: (string) ($nightCount === 0 ? $nightsAmount->toScale(2, RoundingMode::HalfUp) : $nightsAmount->dividedBy($nightCount, 2, RoundingMode::HalfUp)),
            rate_plan: $unit->ratePlan->name,
            meal_plan: app(MealPlanLabel::class)->forRatePlan($unit->ratePlan),
            source: $reservation->bookingSource->name,
            market_segment: $reservation->marketSegment?->name,
            sales_person: $reservation->salesPerson?->name,
            booked_by: $reservation->creator?->name,
            housekeeping_remarks: $remarks->get(RemarkKind::Housekeeping->value),
            dietary_remarks: $remarks->get(RemarkKind::Dietary->value),
            package_remarks: $remarks->get(RemarkKind::Package->value),
            payment_remarks: $remarks->get(RemarkKind::Payment->value),
            pick_up: $pickUp,
            drop_off: $dropOff,
        );
    }
}
