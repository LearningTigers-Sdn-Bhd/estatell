<?php

namespace App\Services\Kiosk;

use App\Enums\UnitStatus;
use App\Models\Guest;
use App\Models\Property;
use App\Models\ReservationUnit;

/**
 * Finds the stay a kiosk works on and the guest who checks in. A stay is a reservation unit that is still Confirmed.
 * Every query is scoped to the token's property, so a stay of another property is the same as a missing one. Reads only.
 */
class KioskStays
{
    /**
     * @return ReservationUnit|null The Confirmed stay with that id in the property.
     */
    public function find(Property $property, int $id): ?ReservationUnit
    {
        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('status', UnitStatus::Confirmed)
            ->find($id);
    }

    /**
     * The Confirmed stay of a reservation reference (any letter case). A reservation with several stays gives the one
     * that arrives first.
     */
    public function findByReference(Property $property, string $reference): ?ReservationUnit
    {
        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('status', UnitStatus::Confirmed)
            ->whereHas('reservation', fn ($query) => $query
                ->where('property_id', $property->id)
                ->whereRaw('lower(reference) = ?', [mb_strtolower(trim($reference))]))
            ->orderBy('arrival_date')
            ->orderBy('id')
            ->first();
    }

    /**
     * The primary guest of the stay, or the booker when it has none (the same person `UnitNationality` reads). Null
     * when that guest was merged into another guest or anonymized.
     */
    public function guest(ReservationUnit $unit): ?Guest
    {
        $primary = $unit->guests()->where('is_primary', true)->with('guest')->first();
        $guest = $primary === null ? $unit->reservation()->firstOrFail()->booker()->first() : $primary->guest;

        return $guest !== null && $guest->merged_into_id === null && $guest->anonymized_at === null ? $guest : null;
    }
}
