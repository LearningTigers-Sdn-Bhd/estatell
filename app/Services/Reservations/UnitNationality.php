<?php

namespace App\Services\Reservations;

use App\Models\ReservationUnit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The nationality that decides a foreign-only tax for a unit: its primary guest, or the booker when the unit has no
 * primary guest. Null when that person has none. It writes nothing.
 * See docs/18-front-desk/01-check-in-and-check-out.md#taxes-and-deposit.
 */
class UnitNationality
{
    public function for(ReservationUnit $unit): ?string
    {
        $primary = $unit->guests()->where('is_primary', true)->with('guest')->first();

        return ($primary === null ? $unit->reservation->booker : $primary->guest)->nationality_code;
    }

    /**
     * The same nationality for many units, without a query for each unit: the units get their primary guest and
     * booker loaded once (relations that are already loaded are reused).
     *
     * @param  EloquentCollection<int, ReservationUnit>  $units
     * @return array<int, string|null> The code by unit id
     */
    public function forUnits(Collection $units): array
    {
        $units->loadMissing(['guests.guest', 'reservation.booker']);

        return $units
            ->mapWithKeys(function (ReservationUnit $unit): array {
                $primary = $unit->guests->firstWhere('is_primary', true);

                return [$unit->id => ($primary === null ? $unit->reservation->booker : $primary->guest)->nationality_code];
            })
            ->all();
    }

    /**
     * Whether a code is foreign: it is set and is not the property's country. This is the rule of `TaxCalculator` for a foreign-only tax.
     */
    public static function isForeign(?string $code, ?string $propertyCountryCode): bool
    {
        return filled($code) && mb_strtoupper(trim((string) $code)) !== mb_strtoupper((string) $propertyCountryCode);
    }
}
