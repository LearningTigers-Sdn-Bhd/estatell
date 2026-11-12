<?php

namespace App\Data\Platform;

use Spatie\LaravelData\Data;

/**
 * The At a glance figures of the admin Overview tab, and the booking website line while Booking engine is on.
 */
class AdminPropertyOverviewData extends Data
{
    public function __construct(
        public int $rooms,
        public int $roomTypes,
        public int $ratePlans,
        public int $bookingsLast30Days,
        public int $staffVerified,
        public int $featuresOn,
        public int $featuresTotal,
        public ?AdminPropertyBookingWebsiteData $bookingWebsite,
    ) {}
}
