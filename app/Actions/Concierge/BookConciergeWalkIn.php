<?php

namespace App\Actions\Concierge;

use App\Actions\Kiosk\BookWalkIn;
use App\Actions\Kiosk\KioskWalkInResult;
use App\Data\Kiosk\KioskWalkInData;
use App\Models\KioskWalkInHold;
use App\Models\Property;

class BookConciergeWalkIn
{
    /** Unpaid holds a property may have open at once, so nobody can lock every room from the lobby page. */
    public const int MAX_OPEN_HOLDS = 10;

    public function __construct(private BookWalkIn $bookWalkIn) {}

    /**
     * Book a room for a guest who has no booking, with the kiosk's walk-in rules: the server prices it, the booking is
     * held for `BookWalkIn::HOLD_MINUTES`, and a hold nobody pays is released by `ReleaseExpiredKioskHold`. The room is
     * the guest's only after the payment step. Refused, writing nothing, with `walk_in_disabled` while the property
     * has the switch off, `busy` while `MAX_OPEN_HOLDS` holds are open, or `no_room_available`.
     */
    public function __invoke(Property $property, KioskWalkInData $search, int $roomTypeId): KioskWalkInResult
    {
        $property->loadMissing('settings');

        if (! $property->settings->concierge_walk_in_enabled) {
            return new KioskWalkInResult(refusal: 'walk_in_disabled');
        }

        $open = KioskWalkInHold::query()->where('property_id', $property->id)->whereNull('released_at')->where('expires_at', '>', now())->count();

        if ($open >= self::MAX_OPEN_HOLDS) {
            return new KioskWalkInResult(refusal: 'busy');
        }

        return $this->bookWalkIn->book($property, null, $search, $roomTypeId);
    }
}
