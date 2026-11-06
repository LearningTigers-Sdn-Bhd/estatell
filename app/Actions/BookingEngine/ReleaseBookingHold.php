<?php

namespace App\Actions\BookingEngine;

use App\Enums\BookingHoldStatus;
use App\Models\BookingHold;

/**
 * Free a guest's rooms at once, when they go back from checkout. Returns false when the hold is not Held, with no write.
 * A guest who still pays later follows the late payment rule.
 */
class ReleaseBookingHold
{
    public function __construct(private AnnounceBookingHoldRooms $announce) {}

    public function __invoke(BookingHold $hold): bool
    {
        $released = BookingHold::query()
            ->whereKey($hold->id)
            ->where('status', BookingHoldStatus::Held)
            ->update(['status' => BookingHoldStatus::Expired, 'updated_at' => now()]);

        if ($released === 0) {
            return false;
        }

        ($this->announce)($hold->refresh());

        return true;
    }
}
