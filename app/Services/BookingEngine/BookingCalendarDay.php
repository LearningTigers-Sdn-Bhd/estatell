<?php

namespace App\Services\BookingEngine;

/**
 * One date of the booking calendar. `lowestPrice` is the lowest one-night price before tax, and is null on a closed date
 * or when only package plans sell the date.
 */
class BookingCalendarDay
{
    public function __construct(
        public readonly string $date,
        public readonly ?string $lowestPrice,
        public readonly bool $closed,
    ) {}
}
