<?php

namespace App\Data\Reports;

use App\Data\Boats\BoatJobData;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\BookingSourceType;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use Spatie\LaravelData\Data;

/**
 * One trip of the Boat manifest: a unit's Boat in or Boat out on the date. The property names are the column keys of
 * `ReportKey::BoatManifest`. `key` is the trip id, or for a unit with no trip in that direction ("Boat not set") a
 * negative key from `notSetKey()`. `boat_departure_id`, `sailing_key`, `sailing_title`, `seats`, `direction` and `not_set` group
 * the row into its section (one per sailing). They are not columns. `agent` is the company, else "Direct booking", else the booking source. `boat` and
 * `crew` come from the row's sailing (Own boat and Charter name the mode). `ticked` says the guests have
 * arrived or left; a row with no trip (`not_set`) cannot be ticked. `mode` is null there. `reservation_id`, `reservation_unit_id`
 * and `unit_time` let the Boats board open the boat Change Dialog of a row; they are not columns.
 */
class BoatManifestRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $departure,
        public string $unit,
        public string $lead_guest,
        public string $agent,
        public ?string $phone,
        public int $adults,
        public int $children,
        public int $total,
        public string $reservation,
        public ?string $note,
        public ?int $boat_departure_id,
        public ?int $seats,
        public string $direction = 'in',
        public bool $not_set = false,
        public ?string $boat = null,
        public ?string $crew = null,
        public bool $ticked = false,
        public ?string $mode = null,
        public ?string $sailing_key = null,
        public ?string $sailing_title = null,
        public ?int $reservation_id = null,
        public ?int $reservation_unit_id = null,
        public ?string $unit_time = null,
    ) {}

    /**
     * The row key of a unit with no trip in `$direction`: negative, so it never meets a trip id, and different for
     * Boat in and Boat out of the same unit.
     */
    public static function notSetKey(int $unitId, BoatDirection $direction): int
    {
        return -($unitId * 2 + ($direction === BoatDirection::Out ? 1 : 0));
    }

    /**
     * The row of a unit that arrives (Boat in) or leaves (Boat out) on the date with no trip set, for the desk to
     * follow up.
     */
    public static function notSet(ReservationUnit $unit, BoatDirection $direction, ?string $room): self
    {
        $occupant = ($unit->guests->firstWhere('is_primary', true) ?? $unit->guests->first())->guest ?? $unit->reservation->booker;

        return new self(
            key: self::notSetKey($unit->id, $direction),
            departure: __('Not set · :time', ['time' => substr($direction === BoatDirection::In ? $unit->arrival_time : $unit->departure_time, 0, 5)]),
            unit: $room ?? $unit->roomType->name,
            lead_guest: $occupant->full_name,
            agent: self::agentOf($unit),
            phone: $occupant->phone,
            adults: $unit->adults,
            children: $unit->children,
            total: $unit->adults + $unit->children,
            reservation: $unit->reservation->reference,
            note: null,
            boat_departure_id: null,
            seats: null,
            direction: $direction->value,
            not_set: true,
            reservation_id: $unit->reservation_id,
            reservation_unit_id: $unit->id,
            unit_time: substr($direction === BoatDirection::In ? $unit->arrival_time : $unit->departure_time, 0, 5),
        );
    }

    /**
     * A boat job as a spreadsheet row, after the guest rows: the time and job in the Departure and Lead guest columns, its
     * boat, crew and note, and Done in the Tick column. It carries no guests. Key 0: it is never ticked on the board.
     */
    public static function job(BoatJobData $job): self
    {
        return new self(
            key: 0,
            departure: __('Boat job · :time', ['time' => $job->leavesAt]),
            unit: '',
            lead_guest: $job->jobLabel,
            agent: '',
            phone: null,
            adults: 0,
            children: 0,
            total: 0,
            reservation: '',
            note: $job->note,
            boat_departure_id: null,
            seats: null,
            direction: '',
            boat: $job->boat,
            crew: $job->crewText(),
            ticked: $job->done,
        );
    }

    private static function agentOf(ReservationUnit $unit): string
    {
        $reservation = $unit->reservation;

        return $reservation->company->name
            ?? ($reservation->bookingSource === null || $reservation->bookingSource->type === BookingSourceType::Direct || $reservation->bookingSource->type === BookingSourceType::WalkIn
                ? __('Direct booking')
                : $reservation->bookingSource->name);
    }

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'departure' => $this->departure,
            'unit' => $this->unit,
            'lead_guest' => $this->lead_guest,
            'agent' => $this->agent,
            'phone' => $this->phone ?? '',
            'adults' => (string) $this->adults,
            'children' => (string) $this->children,
            'total' => (string) $this->total,
            'boat' => $this->boat ?? '',
            'crew' => $this->crew ?? '',
            'tick' => $this->ticked ? __('Yes') : '',
            'reservation' => $this->reservation,
            'note' => $this->note ?? '',
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'departure' => $this->departure,
            'unit' => $this->unit,
            'lead_guest' => $this->lead_guest,
            'agent' => $this->agent,
            'phone' => $this->phone ?? '',
            'adults' => $this->adults,
            'children' => $this->children,
            'total' => $this->total,
            'boat' => $this->boat ?? '',
            'crew' => $this->crew ?? '',
            'tick' => $this->ticked ? __('Yes') : '',
            'reservation' => $this->reservation,
            'note' => $this->note ?? '',
        ];
    }

    /**
     * The row of one trip. The lead guest is the unit's first guest (the primary guest first), else the booker. Charter
     * and own boat trips take their time from the unit: its arrival time for Boat in, its departure time for Boat out.
     *
     * @param  string|null  $room  The room number or bed label of the night the direction needs, when one is assigned.
     * @param  array{key: string, title: string, times: string, boat: ?string, crew: ?string, seats: ?int}|null  $sailing  The sailing of a scheduled trip on the date, named by `SailingLabels`.
     */
    public static function fromModel(UnitBoatTrip $trip, ReservationUnit $unit, ?string $room, ?array $sailing = null): self
    {
        $reservation = $unit->reservation;
        $occupant = ($unit->guests->firstWhere('is_primary', true) ?? $unit->guests->first())->guest ?? $reservation->booker;
        $departure = $trip->boatDeparture;
        $isScheduled = $trip->mode === BoatMode::Scheduled && $departure !== null && $sailing !== null;

        $time = substr($trip->direction === BoatDirection::In ? $unit->arrival_time : $unit->departure_time, 0, 5);

        return new self(
            key: $trip->id,
            departure: $isScheduled
                ? $sailing['times']
                : ($trip->mode === BoatMode::Charter ? __('Charter :time', ['time' => $time]) : __('Own :time', ['time' => $time])),
            unit: $room ?? $unit->roomType->name,
            lead_guest: $occupant->full_name,
            agent: self::agentOf($unit),
            phone: $occupant->phone,
            adults: $unit->adults,
            children: $unit->children,
            total: $unit->adults + $unit->children,
            reservation: $reservation->reference,
            note: $trip->note,
            boat_departure_id: $isScheduled ? $departure->id : null,
            seats: $isScheduled ? $sailing['seats'] : null,
            direction: $trip->direction->value,
            boat: $isScheduled ? $sailing['boat'] : $trip->mode->label(),
            crew: $isScheduled ? $sailing['crew'] : null,
            ticked: $trip->ticked_at !== null,
            mode: $trip->mode->value,
            sailing_key: $isScheduled ? $sailing['key'] : null,
            sailing_title: $isScheduled ? $sailing['title'] : null,
            reservation_id: $unit->reservation_id,
            reservation_unit_id: $unit->id,
            unit_time: $time,
        );
    }
}
