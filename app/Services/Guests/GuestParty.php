<?php

namespace App\Services\Guests;

use App\Models\Guest;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The people of one or more rooms in one line, for the lists that go out with staff who do not use estatell: the
 * guide's participant list and the driver's transfer manifest. Each named guest shows the nationality code and the age
 * on the day, when known: "Hannah Müller (DE, 34) · Tom Lee (GB) · +1 not named". The primary guest of each room comes
 * first. When the rooms hold more people than they name, the rest is counted. A room with no named guest stands for
 * the booker. See docs/35-transfers/01-land-transfers.md#transfer-manifest.
 */
class GuestParty
{
    /**
     * Expects each unit's `guests.guest` loaded. `$booker` stands in when no room names a guest.
     *
     * @param  iterable<ReservationUnit>  $units
     */
    public function line(iterable $units, CarbonImmutable $on, ?Guest $booker): string
    {
        $units = collect($units);
        $named = $units->flatMap(fn (ReservationUnit $unit): Collection => $unit->guests
            ->sortByDesc(fn (UnitGuest $unitGuest): bool => $unitGuest->is_primary)
            ->pluck('guest'));

        if ($named->isEmpty() && $booker !== null) {
            $named = collect([$booker]);
        }

        $named = $named->filter()->unique('id')->values();
        $people = (int) $units->sum(fn (ReservationUnit $unit): int => $unit->adults + $unit->children);
        $parts = $named->map(fn (Guest $guest): string => $this->person($guest, $on))->all();
        $unnamed = $people - $named->count();

        if ($unnamed > 0) {
            $parts[] = __('+:count not named', ['count' => $unnamed]);
        }

        return implode(' · ', $parts);
    }

    private function person(Guest $guest, CarbonImmutable $on): string
    {
        $details = array_filter([
            $guest->nationality_code,
            $guest->date_of_birth === null ? null : (string) (int) $guest->date_of_birth->diffInYears($on),
        ], fn (?string $detail): bool => $detail !== null && $detail !== '');

        return $details === [] ? $guest->full_name : $guest->full_name.' ('.implode(', ', $details).')';
    }
}
