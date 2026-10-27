<?php

namespace App\Actions\Reservations;

use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\Guest;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\User;
use App\Services\Guests\CountryOptions;
use App\Services\Reservations\UnitCapacity;
use App\Services\Reservations\UnitNationality;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AttachGuest
{
    public function __construct(private CountryOptions $countries, private UnitCapacity $capacity) {}

    /**
     * Add a guest as an occupant of a unit. The unit row is locked first, so two staff cannot fill a bed together.
     * The first guest of the unit becomes primary. A bed unit holds one guest (`bed_unit_full`) and a room unit its
     * adults and children (`unit_full`, see `UnitCapacity`). A blacklisted guest is allowed. Every check runs before the first
     * write, so a refusal writes and logs nothing. The log holds ids only. A CheckedOut unit takes a guest too (Cancelled
     * and NoShow do not); nothing is posted. A new primary guest on a CheckedOut unit whose foreign status differs from
     * the booker's, while the property has an enabled foreign-only tax, returns a tax warning.
     * See docs/12-reservations/06-reservation-page.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, Guest $guest): AttachGuestResult
    {
        return DB::transaction(function () use ($actor, $unit, $guest): AttachGuestResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

            $found = Guest::query()
                ->where('property_id', $locked->property_id)
                ->notMerged()
                ->whereNull('anonymized_at')
                ->whereKey($guest->id)
                ->exists();

            if (! $found) {
                return AttachGuestResult::refused(AttachGuestResult::REFUSAL_GUEST_NOT_FOUND);
            }

            if (! in_array($locked->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn, UnitStatus::CheckedOut], true)) {
                return AttachGuestResult::refused(AttachGuestResult::REFUSAL_UNIT_NOT_OPEN);
            }

            if ($locked->guests()->where('guest_id', $guest->id)->exists()) {
                return AttachGuestResult::refused(AttachGuestResult::REFUSAL_ALREADY_ATTACHED);
            }

            $guestCount = $locked->guests()->count();
            $hasGuest = $guestCount > 0;

            if ($guestCount >= $this->capacity->of($locked)) {
                return AttachGuestResult::refused($locked->roomType->sell_unit === SellUnit::Bed ? AttachGuestResult::REFUSAL_BED_UNIT_FULL : AttachGuestResult::REFUSAL_UNIT_FULL);
            }

            $unitGuest = $locked->guests()->make(['guest_id' => $guest->id, 'is_primary' => ! $hasGuest]);
            $unitGuest->save();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.guest_attached')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'guest_id' => $guest->id,
                    'is_primary' => $unitGuest->is_primary,
                ])
                ->log('Guest attached to unit');

            return AttachGuestResult::attached($unitGuest, $this->taxWarning($locked, $guest, $unitGuest->is_primary));
        });
    }

    /**
     * The warning for a guest who becomes primary on a CheckedOut unit, or null.
     */
    public function taxWarning(ReservationUnit $unit, Guest $guest, bool $becamePrimary): ?string
    {
        if ($unit->status !== UnitStatus::CheckedOut || ! $becamePrimary) {
            return null;
        }

        $booker = $unit->reservation->booker;
        $country = $unit->property->country_code;

        if (blank($guest->nationality_code) || blank($booker->nationality_code)
            || UnitNationality::isForeign($guest->nationality_code, $country) === UnitNationality::isForeign($booker->nationality_code, $country)) {
            return null;
        }

        $names = Tax::query()
            ->where('property_id', $unit->property_id)
            ->enabled()
            ->where('foreign_guests_only', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($names === []) {
            return null;
        }

        return __("Guest added. :taxes was charged on the booker's nationality (:country). If it is different for this guest, correct it on the folio.", [
            'taxes' => Arr::join($names, ', ', __(' and ')),
            'country' => $this->countries->name($booker->nationality_code),
        ]);
    }
}
