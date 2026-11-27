<?php

namespace App\Services\Reports\BookingPerformance;

use App\Data\Reservations\BookingSourceBadgeData;
use App\Data\Shared\BadgeData;

/**
 * The name of one Booking performance group, with the badge the Source and Status tabs show.
 */
final readonly class GroupLabel
{
    public function __construct(
        public string $name,
        public ?BookingSourceBadgeData $source = null,
        public ?BadgeData $status = null,
    ) {}
}
