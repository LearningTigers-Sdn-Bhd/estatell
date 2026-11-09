<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The question of the Check in all form when some units were not logged in time. It applies to those units only.
 * `stayDates` is the text of their dates, and `oneNight` is true when each of them is booked for last night alone.
 * `arrivingTodayBlocked` says why the move to today is disabled.
 */
class CheckInAllEndedStayData extends Data
{
    public function __construct(
        public string $stayDates,
        public bool $oneNight,
        public string $lastNight,
        public string $today,
        public string $tomorrow,
        public ?string $arrivingTodayBlocked,
    ) {}
}
