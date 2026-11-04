<?php

namespace App\Services\Kiosk;

use App\Enums\FieldVisibility;
use App\Enums\RegistrationCardField;
use App\Models\Property;

/**
 * Finds the booking a guest types or scans at the kiosk and says whether the guest may start check-in today. It uses the
 * same arrival rule as `CheckInUnit` (the stay day, not the calendar date) and reads only. Estatell has no QR token or
 * pre-check-in link for a booking yet, so a `qr_token` finds nothing.
 * See docs/28-api-access/02-kiosk-check-in-endpoints.md.
 */
class KioskBookingFinder
{
    public function __construct(private KioskStays $stays) {}

    public function byReference(Property $property, string $reference): KioskBookingLookup
    {
        $stay = $this->stays->findByReference($property, $reference);

        if ($stay === null) {
            return KioskBookingLookup::refused(KioskBookingLookup::BOOKING_NOT_FOUND);
        }

        $window = $this->stays->window($stay);

        if ($window !== KioskStays::OPEN) {
            return KioskBookingLookup::refused(match ($window) {
                KioskStays::EARLY => KioskBookingLookup::ARRIVAL_NOT_REACHED,
                KioskStays::NOT_LIVE => KioskBookingLookup::NOT_OPEN,
                default => KioskBookingLookup::BOOKING_NOT_FOUND,
            });
        }

        $stay->loadMissing(['reservation', 'roomType']);

        return KioskBookingLookup::found($stay, (string) $this->stays->guest($stay)?->full_name, $this->stepsRequired($property));
    }

    /**
     * `registration_card` follows the property's rule that a signed card is needed to check in. `id` is on when the
     * registration card marks the ID document field Required (the default for a new property).
     *
     * @return array{id: bool, registration_card: bool}
     */
    private function stepsRequired(Property $property): array
    {
        $visibility = $property->registrationCardFields()->where('field', RegistrationCardField::IdentityDocument)->value('visibility')
            ?? RegistrationCardField::IdentityDocument->defaultVisibility()->value;

        return [
            'id' => $visibility === FieldVisibility::Required->value,
            'registration_card' => (bool) $property->settings?->card_required_for_check_in,
        ];
    }
}
