<?php

namespace App\Services\Concierge;

use App\Actions\Guests\MergeGuests;
use App\Enums\GuestMatchReason;
use App\Models\Guest;
use App\Models\KioskWalkInHold;
use App\Models\ReservationUnit;
use App\Services\Guests\GuestMatcher;
use App\Services\Kiosk\KioskStays;
use Carbon\CarbonInterface;

/**
 * Joins the guest of a Concierge walk-in to a returning guest of the property, so the guest does not type their details
 * again. The page is open to anyone, so an ID number alone proves nothing: the guest must also give the date of birth
 * saved on that profile. Nothing from the profile is shown back to the guest. A guest whose profile has no date of birth
 * is not joined (the ID form is filled this time and saves one for the next stay). A booking made earlier already has a
 * named guest, so only a walk-in's nameless guest is ever merged. See docs/30-concierge/01-overview.md#returning-guests.
 */
class ReturningGuest
{
    public function __construct(private GuestMatcher $matcher, private MergeGuests $mergeGuests, private KioskStays $stays) {}

    public function isWalkIn(ReservationUnit $stay): bool
    {
        return KioskWalkInHold::query()->where('reservation_unit_id', $stay->id)->exists();
    }

    /**
     * Move the stay to the returning guest with this ID number and date of birth. Null, changing nothing, when the stay
     * is not a walk-in with a nameless guest, no date of birth was given, nobody matches both, or the merge did not happen.
     * The match is logged with the guest ids, never the number or the date.
     */
    public function join(ReservationUnit $stay, string $idNumber, ?CarbonInterface $dateOfBirth): ?Guest
    {
        $placeholder = $this->stays->guest($stay);

        if ($idNumber === '' || $dateOfBirth === null || $placeholder === null || filled($placeholder->full_name) || ! $this->isWalkIn($stay)) {
            return null;
        }

        $stay->loadMissing('property');
        $matches = $this->matcher->find($stay->property, idNumbers: [$idNumber], includeIdNumbers: true, excludeGuestId: $placeholder->id);
        $found = array_find($matches, fn (array $match): bool => in_array(GuestMatchReason::IdNumber, $match['reasons'], true)
            && $match['guest']->date_of_birth?->toDateString() === $dateOfBirth->toDateString());

        if ($found === null || ! ($this->mergeGuests)(null, $found['guest'], $placeholder)) {
            return null;
        }

        activity()
            ->performedOn($found['guest'])
            ->event('concierge.returning_guest_joined')
            ->withProperties(['property_id' => $stay->property_id, 'reservation_unit_id' => $stay->id, 'merged_guest_id' => $placeholder->id])
            ->log('Walk-in joined a returning guest by ID number and date of birth');

        return $this->stays->guest($stay);
    }
}
