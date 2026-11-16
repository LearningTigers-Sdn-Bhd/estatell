<?php

namespace App\Services\Kiosk;

use App\Enums\FieldVisibility;
use App\Enums\RegistrationCardField;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Services\Concierge\ConciergeStays;
use App\Services\Registration\RegistrationCardRequirement;
use App\Services\Reservations\UnitNationality;
use Carbon\CarbonImmutable;

/**
 * Finds the booking a guest types or scans at the kiosk and says whether the guest may start check-in today. It uses the
 * same arrival rule as `CheckInUnit` (the stay day, not the calendar date) and reads only. A QR token identifies one
 * stay; ambiguous typed references go to the front desk.
 * See docs/28-api-access/03-kiosk-check-in-endpoints.md.
 */
class KioskBookingFinder
{
    public function __construct(
        private KioskStays $stays,
        private KioskWalkInHolds $holds,
        private UnitNationality $nationality,
        private ConciergeStays $concierge,
        private RegistrationCardRequirement $cards,
    ) {}

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

        $window = $stay->status === UnitStatus::CheckedIn ? KioskStays::OPEN : $this->stays->window($stay);

        if (! in_array($window, [KioskStays::OPEN, KioskStays::EARLY], true)) {
            return KioskBookingLookup::refused(match ($window) {
                KioskStays::NOT_LIVE => KioskBookingLookup::NOT_OPEN,
                default => KioskBookingLookup::BOOKING_NOT_FOUND,
            });
        }

        $stay->loadMissing(['reservation', 'roomType']);

        return KioskBookingLookup::found($stay, (string) $this->stays->guest($stay)?->full_name, $this->stepsRequired($property, $stay), $this->state($stay));
    }

    /**
     * The steps still to do, so a guest who pre-checked in (here or on the Concierge page) is not asked again.
     * `registration_card` is on while the property needs a signed card to check in and one is missing. `id` is on when
     * the registration card marks the ID document field Required (the default for a new property), or when a
     * foreign-guests-only tax needs a nationality the stay does not have yet (only the ID step gives the kiosk one, and
     * check-in refuses with `nationality_required` without it), and the guest has no ID document saved yet.
     *
     * @return array{id: bool, registration_card: bool}
     */
    private function stepsRequired(Property $property, ReservationUnit $stay): array
    {
        $visibility = $property->registrationCardFields()->where('field', RegistrationCardField::IdentityDocument)->value('visibility')
            ?? RegistrationCardField::IdentityDocument->defaultVisibility()->value;
        $needsNationality = $this->needsNationality($property, $stay);
        $idSaved = $this->stays->guest($stay)?->documents()->exists() === true && ! $needsNationality;

        return [
            'id' => ($visibility === FieldVisibility::Required->value || $needsNationality) && ! $idSaved,
            'registration_card' => (bool) $property->settings?->card_required_for_check_in && $this->cards->missingFor($stay, $property)->isNotEmpty(),
        ];
    }

    /**
     * Where the stay is: still to check in, or in-house. A stay still to check in says whether check-in is open (its
     * arrival day reached and the property's check-in time passed) and, when not, when it opens: the guest may then do
     * the ID and card steps (pre-check-in), nothing else. An in-house stay says whether check-out is open, and gives
     * its room only while this kiosk may still show a key (`checkedInByKiosk`).
     *
     * @return array{status: string, check_in_open: bool, check_in_opens_at: string|null, check_out_open: bool, key_available: bool, room_number: string|null}
     */
    private function state(ReservationUnit $stay): array
    {
        if ($stay->status !== UnitStatus::CheckedIn) {
            $open = $this->stays->window($stay) === KioskStays::OPEN && $this->concierge->checkInTimeReached($stay);

            return [
                'status' => 'confirmed',
                'check_in_open' => $open,
                'check_in_opens_at' => $open ? null : $this->opensAt($stay),
                'check_out_open' => false,
                'key_available' => false,
                'room_number' => null,
            ];
        }

        $keyAvailable = $this->stays->checkedInByKiosk($stay);

        return [
            'status' => 'checked_in',
            'check_in_open' => true,
            'check_in_opens_at' => null,
            'check_out_open' => $this->stays->checkOutWindow($stay),
            'key_available' => $keyAvailable,
            'room_number' => $keyAvailable ? $this->stays->roomNumber($stay) : null,
        ];
    }

    /** The arrival day at the property's check-in time, with the property's UTC offset. Without a check-in time, the start of the arrival day. */
    private function opensAt(ReservationUnit $stay): string
    {
        $stay->loadMissing('property.settings');

        return ($this->concierge->checkInOpensAt($stay) ?? CarbonImmutable::parse($stay->arrival_date->toDateString(), $stay->property->settings->time_zone))->toIso8601String();
    }

    private function needsNationality(Property $property, ReservationUnit $stay): bool
    {
        return $this->nationality->for($stay) === null
            && Tax::query()->where('property_id', $property->id)->where('enabled', true)->where('foreign_guests_only', true)->exists();
    }
}
