<?php

namespace App\Services\Meals;

/**
 * Whether a meal counts on the first or last day of a stay, from a time of day. The kitchen counts and the boat
 * departures' Meals column both call it, so they never disagree. Times are `HH:MM` or `HH:MM:SS`.
 */
class MealTimeRule
{
    /** A meal on the arrival day counts when the guest arrives before it ends. */
    public static function countsOnArrival(string $arrivalTime, string $endsAt): bool
    {
        return substr($arrivalTime, 0, 5) < substr($endsAt, 0, 5);
    }

    /** A meal on the departure day counts when the guest leaves after it starts. */
    public static function countsOnDeparture(string $departureTime, string $startsAt): bool
    {
        return substr($departureTime, 0, 5) > substr($startsAt, 0, 5);
    }
}
