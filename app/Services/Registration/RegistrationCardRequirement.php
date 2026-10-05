<?php

namespace App\Services\Registration;

use App\Enums\RegistrationCardScope;
use App\Enums\RegistrationCardStatus;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use Illuminate\Support\Collection;

/**
 * Which guests of a unit still need a signed registration card, by the property's card scope.
 * PrimaryOnly means the primary guest. EveryAdult means every guest, except one known to be under 18 on arrival.
 * Check-in uses it. It writes nothing.
 */
class RegistrationCardRequirement
{
    private const int ADULT_AGE = 18;

    /**
     * @return Collection<int, UnitGuest>
     */
    public function missingFor(ReservationUnit $unit, Property $property): Collection
    {
        $signed = $this->signedUnitGuestIds($unit);

        return $this->requiredFor($unit, $property)->reject(fn (UnitGuest $unitGuest): bool => in_array($unitGuest->id, $signed, true))->values();
    }

    /**
     * @return Collection<int, UnitGuest>
     */
    public function requiredFor(ReservationUnit $unit, Property $property): Collection
    {
        $guests = $unit->guests()->with('guest')->orderByDesc('is_primary')->orderBy('id')->get();

        if (($property->settings?->registration_card_scope ?? RegistrationCardScope::PrimaryOnly) === RegistrationCardScope::PrimaryOnly) {
            return $guests->filter(fn (UnitGuest $unitGuest): bool => $unitGuest->is_primary)->values();
        }

        return $guests->filter(fn (UnitGuest $unitGuest): bool => ! $this->isMinor($unitGuest, $unit))->values();
    }

    /**
     * False for a unit that is cancelled, a no-show or checked out, and for a guest merged into another guest or anonymized.
     */
    public function canHaveCard(UnitGuest $unitGuest): bool
    {
        $unit = $unitGuest->reservationUnit()->firstOrFail();
        $guest = $unitGuest->guest()->firstOrFail();

        return in_array($unit->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true)
            && $guest->merged_into_id === null
            && $guest->anonymized_at === null;
    }

    private function isMinor(UnitGuest $unitGuest, ReservationUnit $unit): bool
    {
        $birth = $unitGuest->guest->date_of_birth;

        return $birth !== null && $birth->diffInYears($unit->arrival_date) < self::ADULT_AGE;
    }

    /**
     * @return list<int>
     */
    private function signedUnitGuestIds(ReservationUnit $unit): array
    {
        return $unit->guests()
            ->whereHas('registrationCards', fn ($query) => $query->where('status', RegistrationCardStatus::Signed->value))
            ->pluck('id')
            ->all();
    }
}
