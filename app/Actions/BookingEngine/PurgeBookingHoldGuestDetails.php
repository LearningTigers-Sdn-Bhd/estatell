<?php

namespace App\Actions\BookingEngine;

use App\Enums\BookingHoldStatus;
use App\Models\BookingHold;

/**
 * Empty the guest details of holds that have been Expired or Confirmed for 30 days. A Paid hold keeps them, because its
 * booking is not made yet. Returns how many changed. Running it again finds nothing more.
 */
class PurgeBookingHoldGuestDetails
{
    public const int AFTER_DAYS = 30;

    public function __invoke(): int
    {
        return BookingHold::query()
            ->whereIn('status', [BookingHoldStatus::Expired, BookingHoldStatus::Confirmed])
            ->where('updated_at', '<=', now()->subDays(self::AFTER_DAYS))
            ->whereNotNull('guest_email')
            ->update([
                'guest_name' => null,
                'guest_email' => null,
                'guest_phone' => null,
                'guest_nationality' => null,
                'special_requests' => null,
                'guest_ip_hash' => null,
            ]);
    }
}
