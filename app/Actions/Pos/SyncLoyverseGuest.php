<?php

namespace App\Actions\Pos;

use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\PosConnection;
use App\Models\PosGuestLink;
use App\Models\ReservationUnit;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Pos\Loyverse\LoyverseClient;
use App\Services\Pos\Loyverse\LoyverseGuestLabel;
use App\Services\Reservations\ReservationRoomBox;

/**
 * Keep one Loyverse customer for a checked-in unit, named "Room 301 - Guest" (or "Bed A1 - Guest"). It creates the customer
 * on check-in and renames it after a room move; a label that has not changed sends nothing. A unit that is no longer
 * checked in is removed instead, and a unit with no room yet is left for the room-change event. The link row remembers the
 * customer id. See docs/33-pos/04-loyverse-integration.md#in-house-guest-sync-estatell--loyverse.
 */
class SyncLoyverseGuest
{
    public function __construct(
        private LoyverseClient $client,
        private ReservationRoomBox $roomBox,
        private CurrentBusinessDate $currentBusinessDate,
        private RemoveLoyverseGuest $remove,
    ) {}

    public function __invoke(PosConnection $connection, int $reservationUnitId): void
    {
        $unit = ReservationUnit::query()
            ->where('property_id', $connection->property_id)
            ->with(['reservation.booker', 'roomType', 'property', 'guests' => fn ($guests) => $guests->where('is_primary', true)->with('guest')])
            ->find($reservationUnitId);

        if ($unit === null || $unit->status !== UnitStatus::CheckedIn) {
            ($this->remove)($connection, $reservationUnitId);

            return;
        }

        $date = ($this->currentBusinessDate->date($unit->property) ?? now())->toDateString();
        $place = $this->roomBox->assignmentsForUnitsOn([$unit->id], $date)[$unit->id] ?? null;

        if ($place === null) {
            return;
        }

        $name = LoyverseGuestLabel::name($place, $unit->roomType->sell_unit === SellUnit::Bed, $unit->guests->first()?->guest->full_name ?? $unit->reservation->booker->full_name);
        $link = PosGuestLink::query()->where('pos_connection_id', $connection->id)->where('reservation_unit_id', $unit->id)->first();

        if ($link !== null && $link->synced_label === $name) {
            return;
        }

        $customerId = $this->client->saveCustomer($connection, $link?->external_customer_id, $name, LoyverseGuestLabel::note($unit->reservation->reference));

        $link ??= new PosGuestLink;
        $link->property_id = $connection->property_id;
        $link->pos_connection_id = $connection->id;
        $link->reservation_unit_id = $unit->id;
        $link->fill(['external_customer_id' => $customerId, 'synced_label' => $name, 'last_synced_at' => now()])->save();
    }
}
