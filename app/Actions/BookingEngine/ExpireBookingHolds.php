<?php

namespace App\Actions\BookingEngine;

use App\Enums\BookingHoldStatus;
use App\Models\BookingHold;

/**
 * Mark Held holds past their expiry as Expired, and tell the channels. This is tidying: availability already ignores them.
 * Returns how many changed. Running it again finds nothing more.
 */
class ExpireBookingHolds
{
    public function __construct(private AnnounceBookingHoldRooms $announce) {}

    public function __invoke(): int
    {
        $expired = 0;

        BookingHold::query()
            ->where('status', BookingHoldStatus::Held)
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($holds) use (&$expired): void {
                foreach ($holds as $hold) {
                    $changed = BookingHold::query()
                        ->whereKey($hold->id)
                        ->where('status', BookingHoldStatus::Held)
                        ->update(['status' => BookingHoldStatus::Expired, 'updated_at' => now()]);

                    if ($changed === 1) {
                        ($this->announce)($hold);
                        $expired++;
                    }
                }
            });

        return $expired;
    }
}
