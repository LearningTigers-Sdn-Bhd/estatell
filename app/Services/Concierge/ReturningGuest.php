<?php

namespace App\Services\Concierge;

use App\Models\Guest;
use App\Models\Property;
use App\Services\Guests\GuestMatcher;
use App\Services\Guests\GuestStayHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds the returning guest of a Concierge walk-in, so the guest does not type their details again. The guest gives the email or the
 * phone number they used before (an `@` makes it an email). It matches when exactly one named profile of the property has it. When
 * several do, they count as one person only if every one of them with a stay has the same name (duplicates of one guest): the one
 * with the most stays (the newest on a tie) matches. Profiles with different names (a family phone) match no one, and so do several
 * profiles with no stays at all; staff merge those guests. It reads only, so it
 * runs before any booking exists; `ConfirmConciergeWalkIn` then books the room for the profile. Nothing from the profile is shown back
 * to the guest except the first name in the greeting. An email or a phone is easy to know, so this trusts it more than an ID number
 * with a date of birth; the throttle on the route limits guessing. See docs/30-concierge/01-overview.md#returning-guests.
 */
class ReturningGuest
{
    public function __construct(private GuestMatcher $matcher) {}

    /**
     * The named profile of the property with this email or phone, or null: nothing given, nobody has it, or several profiles of
     * different people (or none with a stay) do. An `@` makes the contact an email; anything else is read as a phone number.
     */
    public function match(Property $property, string $contact): ?Guest
    {
        $contact = trim($contact);

        if ($contact === '') {
            return null;
        }

        $isEmail = str_contains($contact, '@');
        $guests = collect($this->matcher->find($property, email: $isEmail ? $contact : null, phone: $isEmail ? null : $contact))
            ->pluck('guest')
            ->filter(fn (Guest $guest): bool => filled($guest->full_name));

        return $guests->count() === 1 ? $guests->first() : $this->mostStays($guests);
    }

    /**
     * Of profiles that share the contact, the one with the most stays (newest on a tie), but only when every profile with a stay has
     * the same name, so duplicates of one guest join and two different people do not.
     *
     * @param  Collection<int, Guest>  $guests
     */
    private function mostStays(Collection $guests): ?Guest
    {
        $stays = DB::table('unit_guests')
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_guests.reservation_unit_id')
            ->whereIn('unit_guests.guest_id', $guests->pluck('id')->all())
            ->whereIn('reservation_units.status', GuestStayHistory::stayStatuses())
            ->groupBy('unit_guests.guest_id')
            ->selectRaw('unit_guests.guest_id, COUNT(*) AS stays')
            ->pluck('stays', 'guest_id');

        $visited = $guests->filter(fn (Guest $guest): bool => $stays->has($guest->id));

        if ($visited->pluck('name_key')->unique()->count() !== 1) {
            return null;
        }

        return $visited->sortByDesc(fn (Guest $guest): array => [$stays[$guest->id], $guest->id])->first();
    }
}
