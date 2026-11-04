<?php

namespace App\Services\Kiosk;

use App\Enums\FieldVisibility;
use App\Enums\RegistrationCardField;
use App\Enums\StayType;
use App\Models\Property;
use App\Services\FrontDesk\StayDay;
use App\Services\NightAudit\CurrentBusinessDate;

/**
 * Finds the booking a guest types or scans at the kiosk and says whether the guest may start check-in today. It uses the
 * same arrival rule as `CheckInUnit` (the stay day, not the calendar date) and reads only. Estatell has no QR token or
 * pre-check-in link for a booking yet, so a `qr_token` finds nothing.
 * See docs/28-api-access/02-kiosk-check-in-endpoints.md.
 */
class KioskBookingFinder
{
    public function __construct(
        private KioskStays $stays,
        private CurrentBusinessDate $currentBusinessDate,
        private StayDay $stayDay,
    ) {}

    public function byReference(Property $property, string $reference): KioskBookingLookup
    {
        $stay = $this->stays->findByReference($property, $reference);

        if ($stay === null) {
            return KioskBookingLookup::refused(KioskBookingLookup::BOOKING_NOT_FOUND);
        }

        $businessDate = $this->currentBusinessDate->date($property);

        if ($businessDate === null) {
            return KioskBookingLookup::refused(KioskBookingLookup::NOT_OPEN);
        }

        $day = $this->stayDay->forCheckIn($stay, $businessDate)->toDateString();
        $arrival = $stay->arrival_date->toDateString();
        $ended = $stay->stay_type === StayType::DayUse ? $day > $arrival : $day >= $stay->departure_date->toDateString();

        if ($ended) {
            return KioskBookingLookup::refused(KioskBookingLookup::BOOKING_NOT_FOUND);
        }

        if ($day < $arrival) {
            return KioskBookingLookup::refused(KioskBookingLookup::ARRIVAL_NOT_REACHED);
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
