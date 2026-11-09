<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The two cards of the Check in form for a stay that was not logged in time. `newTotal` is the unit total after a
 * move to today, and `arrivingTodayBlocked` says why that card is disabled.
 */
class EndedStayCheckInData extends Data
{
    public function __construct(
        public string $arrival,
        public string $departure,
        public string $lastNight,
        public string $today,
        public string $tomorrow,
        public ?string $newTotal,
        public ?string $arrivingTodayBlocked,
    ) {}
}
