<?php

namespace App\Services\Kiosk;

use App\Enums\FieldVisibility;
use App\Enums\RegistrationCardField;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Services\Reservations\UnitNationality;

/**
 * Finds the booking a guest types or scans at the kiosk and says whether the guest may start check-in today. It uses the
 * same arrival rule as `CheckInUnit` (the stay day, not the calendar date) and reads only. A QR token identifies one
 * stay; ambiguous typed references go to the front desk.
 * See docs/28-api-access/03-kiosk-check-in-endpoints.md.
 */
class KioskBookingFinder
{
    public function __construct(private KioskStays $stays, private KioskWalkInHolds $holds, private UnitNationality $nationality) {}

    public function byReference(Property $property, string $reference): KioskBookingLookup
    {
        $stays = $this->stays->byReference($property, $reference);

        if ($stays->isEmpty() && $this->holds->expiredReference($property, $reference)) {
            return KioskBookingLookup::refused('hold_expired');
        }

        return $stays->count() > 1
            ? KioskBookingLookup::refused(KioskBookingLookup::AMBIGUOUS)
            : $this->lookup($property, $stays->first());
    }

    public function byQrToken(Property $property, string $token): KioskBookingLookup
    {
        return $this->lookup($property, $this->stays->byQrToken($property, $token));
    }

    private function lookup(Property $property, ?ReservationUnit $stay): KioskBookingLookup
    {

        if ($stay === null) {
            return KioskBookingLookup::refused(KioskBookingLookup::BOOKING_NOT_FOUND);
        }

        if ($this->holds->expired($property, $stay->id)) {
            return KioskBookingLookup::refused('hold_expired');
        }

        $window = $stay->status === UnitStatus::CheckedIn
            ? ($this->stays->checkOutWindow($stay) ? KioskStays::OPEN : KioskStays::ENDED)
            : $this->stays->window($stay);

        if ($window !== KioskStays::OPEN) {
            return KioskBookingLookup::refused(match ($window) {
                KioskStays::EARLY => KioskBookingLookup::ARRIVAL_NOT_REACHED,
                KioskStays::NOT_LIVE => KioskBookingLookup::NOT_OPEN,
                default => KioskBookingLookup::BOOKING_NOT_FOUND,
            });
        }

        $stay->loadMissing(['reservation', 'roomType']);

        return KioskBookingLookup::found($stay, (string) $this->stays->guest($stay)?->full_name, $this->stepsRequired($property, $stay));
    }

    /**
     * `registration_card` follows the property's rule that a signed card is needed to check in. `id` is on when the
     * registration card marks the ID document field Required (the default for a new property), and also when a
     * foreign-guests-only tax needs a nationality the stay does not have yet: only the ID step gives the kiosk one, and
     * check-in refuses with `nationality_required` without it.
     *
     * @return array{id: bool, registration_card: bool}
     */
    private function stepsRequired(Property $property, ReservationUnit $stay): array
    {
        $visibility = $property->registrationCardFields()->where('field', RegistrationCardField::IdentityDocument)->value('visibility')
            ?? RegistrationCardField::IdentityDocument->defaultVisibility()->value;

        return [
            'id' => $visibility === FieldVisibility::Required->value || $this->needsNationality($property, $stay),
            'registration_card' => (bool) $property->settings?->card_required_for_check_in,
        ];
    }

    private function needsNationality(Property $property, ReservationUnit $stay): bool
    {
        return $this->nationality->for($stay) === null
            && Tax::query()->where('property_id', $property->id)->where('enabled', true)->where('foreign_guests_only', true)->exists();
    }
}
