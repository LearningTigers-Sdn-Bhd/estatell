<?php

namespace App\Services\BookingEngine;

use App\Models\Property;
use App\Services\Property\PropertyToday;
use Carbon\CarbonInterface;

/**
 * The arrival dates a guest can book online: from today, at the property, to at most 6 months ahead. Curlec's normal
 * refunds fail on older payments. See docs/29-booking-engine/01-overview.md#decisions.
 */
class BookingWindow
{
    public const int MONTHS_AHEAD = 6;

    public function __construct(private PropertyToday $propertyToday) {}

    public function contains(Property $property, CarbonInterface $arrival): bool
    {
        $today = $this->propertyToday->date($property);
        $date = $arrival->toDateString();

        return $date >= $today->toDateString() && $date <= $today->addMonthsNoOverflow(self::MONTHS_AHEAD)->toDateString();
    }
}
