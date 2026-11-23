<?php

namespace App\Services\Pos;

use App\Enums\FolioStatus;
use App\Enums\UnitStatus;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Folio\FolioRouter;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Reservations\ReservationRoomBox;

/**
 * Finds the checked-in units of a property for a POS cashier, by exact room or by a partial match on room number,
 * primary guest name or reservation reference. It returns at most 25 and never any identity document or card data.
 * `can_post_charges` is true when `FolioRouter` finds no folio yet (one will be made) or an Open one. It reads only.
 * See docs/33-pos/03-pos-api.md.
 */
class InHouseGuestLookup
{
    private const int LIMIT = 25;

    public function __construct(
        private ReservationRoomBox $roomBox,
        private FolioRouter $folioRouter,
        private CurrentBusinessDate $currentBusinessDate,
    ) {}

    /**
     * @return list<array{reservation_unit_id: int, reservation_reference: string, room_number: string, guest_name: string, arrival_date: string, departure_date: string, can_post_charges: bool}>
     */
    public function find(Property $property, ?string $query, ?string $room): array
    {
        $units = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('status', UnitStatus::CheckedIn)
            ->with(['reservation.booker', 'guests' => fn ($guests) => $guests->where('is_primary', true)->with('guest')])
            ->orderBy('id')
            ->get();

        $date = ($this->currentBusinessDate->date($property) ?? now())->toDateString();
        $rooms = $this->roomBox->assignmentsForUnitsOn(array_values(array_map(intval(...), $units->modelKeys())), $date);
        $code = PosOutlet::query()->where('property_id', $property->id)->active()->with('defaultChargeItem.transactionCode')->orderBy('id')->first()?->defaultChargeItem?->transactionCode;
        $needle = $query === null ? '' : mb_strtolower(trim($query));
        $room = $room === null ? '' : mb_strtolower(trim($room));
        $rows = [];

        foreach ($units as $unit) {
            $roomNumber = $rooms[$unit->id] ?? '';
            $guestName = $unit->guests->first()?->guest->full_name ?? $unit->reservation->booker->full_name;
            $haystack = mb_strtolower($roomNumber.' '.$guestName.' '.$unit->reservation->reference);

            if (($room !== '' && mb_strtolower($roomNumber) !== $room) || ($needle !== '' && ! str_contains($haystack, $needle))) {
                continue;
            }

            $folio = $code === null ? $this->folioRouter->unitFolio($unit) : $this->folioRouter->targetFor($unit, $code);
            $rows[] = [
                'reservation_unit_id' => $unit->id,
                'reservation_reference' => $unit->reservation->reference,
                'room_number' => $roomNumber,
                'guest_name' => $guestName,
                'arrival_date' => $unit->arrival_date->toDateString(),
                'departure_date' => $unit->departure_date->toDateString(),
                'can_post_charges' => $folio === null || $folio->status === FolioStatus::Open,
            ];

            if (count($rows) === self::LIMIT) {
                break;
            }
        }

        return $rows;
    }
}
