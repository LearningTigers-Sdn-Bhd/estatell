<?php

namespace App\Services\Folio;

use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\ReservationUnit;
use App\Services\Reservations\ReservationRoomBox;
use Carbon\CarbonImmutable;

/**
 * Names the live units (Confirmed or CheckedIn) whose unposted Scheduled rows still route to a folio, with their
 * dates, for the `folio_receives_scheduled_charges` refusal of `CloseFolio`, `SettleToCityLedger` and `CheckOutUnit`.
 * Every unposted row counts, whatever its date: tonight's night is posted by tonight's audit, and an older night that
 * failed is retried by the next one. The rows and their targets come from `ScheduledFolioRows`, the same rows as the
 * folio tab. It reads only. See docs/18-front-desk/01-check-in-and-check-out.md#rooms-that-charge-to-another-rooms-folio.
 */
class IncomingScheduledCharges
{
    public function __construct(
        private ScheduledFolioRows $scheduledRows,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * The `folio_receives_scheduled_charges` message for the rooms `for()` returned.
     *
     * @param  list<string>  $rooms
     */
    public static function message(array $rooms): string
    {
        if (count($rooms) === 1) {
            return __(':room still charges its scheduled nights to this folio. Send them to another folio first.', ['room' => $rooms[0]]);
        }

        $last = array_pop($rooms);

        return __(':rooms and :last still charge their scheduled nights to this folio. Send them to another folio first.', ['rooms' => implode(', ', $rooms), 'last' => $last]);
    }

    /**
     * Each live unit that still charges Scheduled rows to the folio, as its name and dates: "Room 102 (15–16 Oct)".
     * Empty when none does. `$exceptUnitId` leaves out the unit being checked out, which stops being live.
     *
     * @return list<string>
     */
    public function for(Folio $folio, ?int $exceptUnitId = null): array
    {
        $reservation = $folio->reservation;

        if ($reservation === null) {
            return [];
        }

        $liveIds = $reservation->units()
            ->whereIn('status', [UnitStatus::Confirmed, UnitStatus::CheckedIn])
            ->when($exceptUnitId !== null, fn ($query) => $query->whereKeyNot($exceptUnitId))
            ->pluck('id')
            ->flip();

        if ($liveIds->isEmpty()) {
            return [];
        }

        $property = $folio->property()->with('settings')->firstOrFail();
        $reservation->loadMissing('booker');
        $dates = collect($this->scheduledRows->forReservation($property, $reservation, []))
            ->filter(fn (array $block): bool => $block['target'] === $folio->id && $liveIds->has($block['unit']))
            ->groupBy('unit')
            ->sortKeys()
            ->map(fn ($blocks): array => $blocks->pluck('date')->unique()->sort()->values()->all());

        if ($dates->isEmpty()) {
            return [];
        }

        $names = $this->unitNames($dates->keys()->all());

        return array_values($dates
            ->map(fn (array $days, int $unitId): string => $names[$unitId].' ('.$this->days($days).')')
            ->values()
            ->all());
    }

    /**
     * The name staff know a unit by: "Room 102" or "Bed A1" from its first night with one, or its room type.
     *
     * @param  list<int>  $unitIds
     * @return array<int, string> By unit id
     */
    public function unitNames(array $unitIds): array
    {
        $assigned = $this->roomBox->assignmentsForUnits($unitIds);

        return ReservationUnit::query()
            ->whereIn('id', $unitIds)
            ->with('roomType:id,name,sell_unit')
            ->get()
            ->mapWithKeys(fn (ReservationUnit $unit): array => [$unit->id => match (true) {
                ! isset($assigned[$unit->id]) => $unit->roomType->name,
                $unit->roomType->sell_unit === SellUnit::Bed => __('Bed :label', ['label' => $assigned[$unit->id]]),
                default => __('Room :label', ['label' => $assigned[$unit->id]]),
            }])
            ->all();
    }

    /**
     * "15 Oct" or "15–16 Oct", from the first to the last date.
     *
     * @param  list<string>  $days
     */
    private function days(array $days): string
    {
        $first = CarbonImmutable::parse($days[0]);
        $last = CarbonImmutable::parse($days[count($days) - 1]);

        return $first->isSameDay($last) ? $first->format('j M') : DateRange::short($first, $last);
    }
}
