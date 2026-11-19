<?php

namespace App\Services\Concierge;

use App\Models\Guest;
use App\Models\Property;
use App\Services\Guests\GuestMatcher;

/**
 * Finds the returning guest of a Concierge walk-in, so the guest does not type their details again. The guest gives the email or the
 * phone number they used before (an `@` makes it an email). It matches only when exactly one named profile of the property has it:
 * a number or address shared by two profiles (a family phone) matches no one, and staff merge those guests. It reads only, so it
 * runs before any booking exists; `ConfirmConciergeWalkIn` then books the room for the profile. Nothing from the profile is shown back
 * to the guest except the first name in the greeting. An email or a phone is easy to know, so this trusts it more than an ID number
 * with a date of birth; the throttle on the route limits guessing. See docs/30-concierge/01-overview.md#returning-guests.
 */
class ReturningGuest
{
    public function __construct(private GuestMatcher $matcher) {}

    /**
     * The one named profile of the property with this email or phone, or null: nothing given, nobody has it, or more than one profile
     * does. An `@` makes the contact an email; anything else is read as a phone number.
     */
    public function match(Property $property, string $contact): ?Guest
    {
        $contact = trim($contact);

        if ($contact === '') {
            return null;
        }

        $isEmail = str_contains($contact, '@');
        $matches = array_values(array_filter(
            $this->matcher->find($property, email: $isEmail ? $contact : null, phone: $isEmail ? null : $contact),
            fn (array $match): bool => filled($match['guest']->full_name),
        ));

        return count($matches) === 1 ? $matches[0]['guest'] : null;
    }
}
