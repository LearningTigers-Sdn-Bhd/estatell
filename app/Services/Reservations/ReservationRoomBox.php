<?php

namespace App\Services\Reservations;

use App\Data\Reservations\ReservationRoomBoxData;
use App\Data\Reservations\ReservationRoomBoxUnitData;
use App\Enums\ReservationRoomBoxState;
use App\Enums\ReservationStatus;
use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out the room box of many reservations with one query for the rooms and beds of the first night of each unit.
 * Expects `units.roomType` to be loaded on every reservation.
 */
class ReservationRoomBox
{
    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return array<int, ReservationRoomBoxData> By reservation id
     */
    public function forReservations(Collection $reservations, bool $autoAssignRooms): array
    {
        $assigned = $this->firstNightAssignments($reservations);
        $boxes = [];

        foreach ($reservations as $reservation) {
            $boxes[$reservation->id] = $this->box($reservation, $assigned, $autoAssignRooms);
        }

        return $boxes;
    }

    /**
     * @param  array<int, string>  $assigned  Room number or bed label by unit id
     */
    private function box(Reservation $reservation, array $assigned, bool $autoAssignRooms): ReservationRoomBoxData
    {
        $everyUnit = array_values($reservation->units
            ->sortBy('id')
            ->map(fn (ReservationUnit $unit): ReservationRoomBoxUnitData => ReservationRoomBoxUnitData::fromModel($unit, $assigned[$unit->id] ?? null))
            ->values()
            ->all());

        if ($reservation->status === ReservationStatus::Cancelled) {
            return $this->iconBox(ReservationRoomBoxState::Cancelled, __('Cancelled'), $everyUnit);
        }

        $live = $reservation->units->filter(fn (ReservationUnit $unit): bool => $unit->status->holdsInventory())->values();

        if ($live->isEmpty()) {
            return $reservation->units->contains(fn (ReservationUnit $unit): bool => $unit->status === UnitStatus::NoShow)
                ? $this->iconBox(ReservationRoomBoxState::NoShow, __('No-show'), $everyUnit)
                : $this->iconBox(ReservationRoomBoxState::Cancelled, __('Cancelled'), $everyUnit);
        }

        $numbers = $live->map(fn (ReservationUnit $unit): ?string => $assigned[$unit->id] ?? null);
        $assignedNumbers = $numbers->filter()->values();
        $unassigned = $live->filter(fn (ReservationUnit $unit): bool => ! isset($assigned[$unit->id]))->values();
        $isGroup = $live->count() > 1;
        $units = $everyUnit;

        if ($assignedNumbers->isNotEmpty()) {
            return new ReservationRoomBoxData(
                state: ReservationRoomBoxState::Assigned,
                label: ReservationRoomBoxState::Assigned->label(),
                tone: ReservationRoomBoxState::Assigned->tone(),
                tooltip: null,
                numbers: array_values($assignedNumbers->take(2)->all()),
                more: max(0, $assignedNumbers->count() - 2),
                count: $unassigned->count(),
                isBed: false,
                units: $units,
            );
        }

        $isBed = $unassigned->every(fn (ReservationUnit $unit): bool => $unit->roomType->sell_unit === SellUnit::Bed);

        if ($autoAssignRooms) {
            return new ReservationRoomBoxData(
                state: ReservationRoomBoxState::AutoAssign,
                label: ReservationRoomBoxState::AutoAssign->label(),
                tone: ReservationRoomBoxState::AutoAssign->tone(),
                tooltip: __('Room assigned automatically'),
                numbers: [],
                more: 0,
                count: $unassigned->count(),
                isBed: $isBed,
                units: $units,
            );
        }

        $tooltip = $isGroup
            ? __(':count units without a room or bed', ['count' => $unassigned->count()])
            : ($isBed ? __('Bed not assigned') : __('Room not assigned'));

        return new ReservationRoomBoxData(
            state: ReservationRoomBoxState::Unassigned,
            label: ReservationRoomBoxState::Unassigned->label(),
            tone: ReservationRoomBoxState::Unassigned->tone(),
            tooltip: $tooltip,
            numbers: [],
            more: 0,
            count: $unassigned->count(),
            isBed: $isBed,
            units: $units,
        );
    }

    /**
     * @param  list<ReservationRoomBoxUnitData>  $units
     */
    private function iconBox(ReservationRoomBoxState $state, string $tooltip, array $units): ReservationRoomBoxData
    {
        return new ReservationRoomBoxData($state, $state->label(), $state->tone(), $tooltip, [], 0, 0, false, $units);
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return array<int, string>
     */
    private function firstNightAssignments(Collection $reservations): array
    {
        return $this->assignmentsForUnits(array_values(array_map(intval(...), $reservations->flatMap(fn (Reservation $reservation) => $reservation->units->pluck('id'))->all())));
    }

    /**
     * The room number or bed label of the first night of every unit that has one, with one query.
     *
     * @param  list<int>  $unitIds
     * @return array<int, string> By unit id
     */
    public function assignmentsForUnits(array $unitIds): array
    {
        return $this->assignments($unitIds, fn (Builder $query) => $query
            ->whereRaw('unit_nights.date = (select min(first_night.date) from unit_nights as first_night where first_night.reservation_unit_id = unit_nights.reservation_unit_id)'));
    }

    /**
     * The room number or bed label of the last night of every unit that has one, with one query.
     *
     * @param  list<int>  $unitIds
     * @return array<int, string> By unit id
     */
    public function lastNightAssignmentsForUnits(array $unitIds): array
    {
        return $this->assignments($unitIds, fn (Builder $query) => $query
            ->whereRaw('unit_nights.date = (select max(last_night.date) from unit_nights as last_night where last_night.reservation_unit_id = unit_nights.reservation_unit_id)'));
    }

    /**
     * The room number or bed label of the night of one date for every unit that has one, with one query.
     *
     * @param  list<int>  $unitIds
     * @param  string  $date  `Y-m-d`
     * @return array<int, string> By unit id
     */
    public function assignmentsForUnitsOn(array $unitIds, string $date): array
    {
        return $this->assignments($unitIds, fn (Builder $query) => $query->where('unit_nights.date', $date), $date);
    }

    /**
     * @param  list<int>  $unitIds
     * @param  callable(Builder): mixed  $night  Narrows the query to one night per unit.
     * @param  string|null  $date  A day use unit shows its room only when its date matches. Null shows it always.
     * @return array<int, string> By unit id
     */
    private function assignments(array $unitIds, callable $night, ?string $date = null): array
    {
        if ($unitIds === []) {
            return [];
        }

        $query = DB::table('unit_nights')
            ->leftJoin('rooms', 'rooms.id', '=', 'unit_nights.room_id')
            ->leftJoin('beds', 'beds.id', '=', 'unit_nights.bed_id')
            ->whereIn('unit_nights.reservation_unit_id', $unitIds);
        $night($query);

        $dayUse = DB::table('unit_day_uses')
            ->join('rooms', 'rooms.id', '=', 'unit_day_uses.room_id')
            ->whereIn('unit_day_uses.reservation_unit_id', $unitIds)
            ->when($date !== null, fn ($rows) => $rows->where('unit_day_uses.date', $date))
            ->select('unit_day_uses.reservation_unit_id', DB::raw('rooms.number as assigned'));

        return $query
            ->select('unit_nights.reservation_unit_id', DB::raw('coalesce(rooms.number, beds.label) as assigned'))
            ->unionAll($dayUse)
            ->get()
            ->filter(fn (object $row): bool => $row->assigned !== null)
            ->mapWithKeys(fn (object $row): array => [(int) $row->reservation_unit_id => (string) $row->assigned])
            ->all();
    }
}
