<?php

namespace App\Services\Concierge;

use App\Actions\Guests\MergeGuests;
use App\Models\Guest;
use App\Models\KioskWalkInHold;
use App\Models\ReservationUnit;
use App\Services\Guests\GuestMatcher;
use App\Services\Kiosk\KioskStays;

/**
 * Joins the guest of a Concierge walk-in to a returning guest of the property, so the guest does not type their details
 * again. The guest gives the email or the phone number they used before (an `@` makes it an email). It joins only when
 * exactly one named profile of the property has it: a number or address shared by two profiles (a family phone) joins no one,
 * and staff merge those guests. Nothing from the profile is shown back to the guest. A booking made earlier already has a
 * named guest, so only a walk-in's nameless guest is ever merged. An email or a phone is easy to know, so this trusts it more
 * than an ID number with a date of birth did; the throttle on the route limits guessing. See
 * docs/30-concierge/01-overview.md#returning-guests.
 */
class ReturningGuest
{
    public function __construct(private GuestMatcher $matcher, private MergeGuests $mergeGuests, private KioskStays $stays) {}

    public function isWalkIn(ReservationUnit $stay): bool
    {
        return KioskWalkInHold::query()->where('reservation_unit_id', $stay->id)->exists();
    }

    /**
     * Move the stay to the returning guest with this email or phone. Null, changing nothing, when the stay is not a
     * walk-in with a nameless guest, nothing was given, not exactly one named profile has it, or the merge did not happen.
     * The match is logged with the guest ids, never the email or the number.
     */
    public function join(ReservationUnit $stay, string $contact): ?Guest
    {
        $contact = trim($contact);
        $placeholder = $this->stays->guest($stay);

        if ($contact === '' || $placeholder === null || filled($placeholder->full_name) || ! $this->isWalkIn($stay)) {
            return null;
        }

        $stay->loadMissing('property');
        $isEmail = str_contains($contact, '@');
        $matches = array_values(array_filter(
            $this->matcher->find($stay->property, email: $isEmail ? $contact : null, phone: $isEmail ? null : $contact, excludeGuestId: $placeholder->id),
            fn (array $match): bool => filled($match['guest']->full_name),
        ));

        if (count($matches) !== 1 || ! ($this->mergeGuests)(null, $matches[0]['guest'], $placeholder)) {
            return null;
        }

        activity()
            ->performedOn($matches[0]['guest'])
            ->event('concierge.returning_guest_joined')
            ->withProperties(['property_id' => $stay->property_id, 'reservation_unit_id' => $stay->id, 'merged_guest_id' => $placeholder->id])
            ->log('Walk-in joined a returning guest by email or phone');

        return $this->stays->guest($stay);
    }
}
