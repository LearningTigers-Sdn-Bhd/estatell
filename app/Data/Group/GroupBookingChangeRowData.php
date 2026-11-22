<?php

namespace App\Data\Group;

use App\Data\Reports\BookingChangeRow;
use Spatie\LaravelData\Data;

/**
 * One row of Group reports › Bookings: one change to a booking at one property of the group (Rooms on books ›
 * Changes), with the property it belongs to. See docs/03-staff/05-group-mode.md.
 */
class GroupBookingChangeRowData extends Data
{
    public function __construct(
        public string $propertyCode,
        public string $propertyName,
        public BookingChangeRow $change,
    ) {}

    /**
     * @return array<string, string|int|float|null>
     */
    public function csv(): array
    {
        return ['property' => $this->propertyName, ...$this->change->values()];
    }
}
