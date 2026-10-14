<?php

namespace App\Services\Folio;

use App\Enums\FolioType;
use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\ReservationUnit;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Reservations\ReservationRoomBox;

/**
 * Names the units of a Master folio's reservation that are still CheckedIn, for the `units_in_house` refusal of
 * `CloseFolio` and `SettleToCityLedger`. Only a Master folio is checked: a Unit or ThirdParty folio gets an empty list.
 * A unit is named by tonight's room or bed ("Room 103", "Bed A1"), or by its room type when it has none. It reads only.
 * See docs/13-folio/01-folios-and-lines.md#close-and-reopen.
 */
class InHouseUnits
{
    public function __construct(
        private ReservationRoomBox $roomBox,
        private CurrentBusinessDate $currentBusinessDate,
    ) {}

    /**
     * The `units_in_house` message for the rooms `roomsFor()` returned.
     *
     * @param  list<string>  $rooms
     */
    public static function message(array $rooms): string
    {
        if (count($rooms) === 1) {
            return __(':rooms is still in-house. Check out all units first.', ['rooms' => $rooms[0]]);
        }

        $prefix = __('Room :label', ['label' => '']);
        $allRooms = collect($rooms)->every(fn (string $room): bool => str_starts_with($room, $prefix));

        return $allRooms
            ? __('Rooms :rooms are still in-house. Check out all units first.', ['rooms' => implode(', ', array_map(fn (string $room): string => substr($room, strlen($prefix)), $rooms))])
            : __(':rooms are still in-house. Check out all units first.', ['rooms' => implode(', ', $rooms)]);
    }

    /**
     * @return list<string>
     */
    public function roomsFor(Folio $folio): array
    {
        if ($folio->type !== FolioType::Master) {
            return [];
        }

        $units = ReservationUnit::query()
            ->where('reservation_id', $folio->reservation_id)
            ->where('status', UnitStatus::CheckedIn)
            ->with('roomType')
            ->orderBy('id')
            ->get();

        if ($units->isEmpty()) {
            return [];
        }

        $date = $this->currentBusinessDate->date($folio->loadMissing('property')->property);
        $assigned = $date === null ? [] : $this->roomBox->assignmentsForUnitsOn($units->modelKeys(), $date->toDateString());

        return array_values($units
            ->map(fn (ReservationUnit $unit): string => isset($assigned[$unit->id])
                ? ($unit->roomType->sell_unit === SellUnit::Bed ? __('Bed :label', ['label' => $assigned[$unit->id]]) : __('Room :label', ['label' => $assigned[$unit->id]]))
                : $unit->roomType->name)
            ->values()
            ->all());
    }
}
