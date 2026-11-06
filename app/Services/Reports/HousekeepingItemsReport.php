<?php

namespace App\Services\Reports;

use App\Data\Reports\HousekeepingItemRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Enums\ChargingUnit;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Services\Reservations\ReservationRoomBox;
use Carbon\CarbonImmutable;

/**
 * The items housekeeping sets up in each room on one date: the add-ons of charge items marked "Show to housekeeping"
 * (an extra bed). An item is in the room on the nights it covers: every night of the stay for an undated or one-off
 * item, the nights of its rows for a nightly one. On the date it is Set up (its first night), In room, or Remove (the
 * day after its last night). Units of every status that holds a room count, cancelled and no-show units do not.
 */
class HousekeepingItemsReport implements HasTotals, Report
{
    public function __construct(private ReservationRoomBox $roomBox) {}

    /**
     * @param  list<int|string>|null  $rowKeys  `{unit id}:{charge item id}` keys.
     * @return list<HousekeepingItemRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $date = CarbonImmutable::parse($filters->from)->startOfDay();
        $yesterday = $date->subDay()->toDateString();
        $today = $date->toDateString();

        $units = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->where('stay_type', StayType::Overnight->value)
            ->whereDate('arrival_date', '<=', $today)
            ->whereDate('departure_date', '>=', $today)
            ->whereHas('addOns.chargeItem', fn ($items) => $items->where('housekeeping_item', true))
            ->with(['reservation.booker', 'roomType', 'guests.guest', 'addOns' => fn ($addOns) => $addOns->whereHas('chargeItem', fn ($items) => $items->where('housekeeping_item', true)), 'addOns.chargeItem'])
            ->orderBy('id')
            ->get();

        $unitIds = array_values(array_map(intval(...), $units->modelKeys()));
        $roomsToday = $this->roomBox->assignmentsForUnitsOn($unitIds, $today);
        $lastNight = $this->roomBox->lastNightAssignmentsForUnits($unitIds);
        $rows = [];

        foreach ($units as $unit) {
            foreach ($unit->addOns->groupBy('charge_item_id') as $itemId => $addOns) {
                $nights = [];

                foreach ($addOns as $addOn) {
                    foreach ($this->nightsOf($addOn, $unit) as $night) {
                        $nights[$night] = ($nights[$night] ?? 0) + $addOn->quantity;
                    }
                }

                $action = match (true) {
                    isset($nights[$today]) && ! isset($nights[$yesterday]) => __('Set up'),
                    isset($nights[$today]) => __('In room'),
                    isset($nights[$yesterday]) => __('Remove'),
                    default => null,
                };

                if ($action === null) {
                    continue;
                }

                $key = $unit->id.':'.$itemId;

                if ($rowKeys !== null && ! in_array($key, array_map(strval(...), $rowKeys), true)) {
                    continue;
                }

                $occupant = ($unit->guests->firstWhere('is_primary', true) ?? $unit->guests->first())->guest ?? $unit->reservation->booker;

                $rows[] = new HousekeepingItemRow(
                    key: $key,
                    room: $roomsToday[$unit->id] ?? $lastNight[$unit->id] ?? $unit->roomType->name,
                    guest: $occupant->full_name,
                    pax: $unit->adults + $unit->children,
                    item: $addOns->first()->chargeItem->name,
                    quantity: $nights[$today] ?? $nights[$yesterday],
                    action: $action,
                    arrival: $unit->arrival_date->toDateString(),
                    departure: $unit->departure_date->toDateString(),
                );
            }
        }

        return $rows;
    }

    /**
     * The items of all rooms.
     *
     * @param  list<HousekeepingItemRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'room' => null,
            'guest' => null,
            'pax' => null,
            'item' => null,
            'quantity' => array_sum(array_map(fn (HousekeepingItemRow $row): int => $row->quantity, $rows)),
            'action' => null,
            'stay' => null,
        ]);
    }

    /**
     * The nights (`Y-m-d`) an add-on row puts its item in the room: its own date for a nightly row, else every night
     * of the stay.
     *
     * @return list<string>
     */
    private function nightsOf(UnitAddOn $addOn, ReservationUnit $unit): array
    {
        $nightly = in_array($addOn->chargeItem->charging_unit, [ChargingUnit::PerNight, ChargingUnit::PerGuestNight], true);

        if ($nightly && $addOn->service_date !== null) {
            return [$addOn->service_date->toDateString()];
        }

        $nights = [];

        for ($night = $unit->arrival_date->toImmutable(); $night->lt($unit->departure_date); $night = $night->addDay()) {
            $nights[] = $night->toDateString();
        }

        return $nights;
    }
}
