<?php

namespace App\Data\Platform;

use Spatie\LaravelData\Data;

/**
 * One month on the admin dashboard charts, in Malaysia time.
 */
class AdminDashboardMonthData extends Data
{
    public function __construct(
        /** `Y-m`. */
        public string $month,
        public int $signUps,
        public int $goLives,
        /** Estatell's commission income from the booking engine, a positive decimal string. */
        public string $bookingEngine,
        /** Estatell's commission income from the channel manager, a positive decimal string. */
        public string $channelManager,
    ) {}
}
