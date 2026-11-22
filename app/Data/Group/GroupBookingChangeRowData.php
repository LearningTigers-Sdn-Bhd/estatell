<?php

namespace App\Data\Group;

use App\Data\Reports\BookingChangeRow;
use App\Data\Reports\ReportColumn;
use App\Enums\ReportKey;
use Spatie\LaravelData\Data;

/**
 * One row of Group reports › Bookings: one change to a booking at one property of the group (Rooms on books ›
 * Changes), with the property it belongs to. `canOpen` says whether the role there may view the reservation.
 * See docs/03-staff/05-group-mode.md.
 */
class GroupBookingChangeRowData extends Data
{
    public function __construct(
        public string $propertyCode,
        public string $propertyName,
        public BookingChangeRow $change,
        public bool $canOpen,
    ) {}

    /**
     * The CSV headings: Property, then the Rooms on books › Changes columns.
     *
     * @return list<string>
     */
    public static function csvHeadings(): array
    {
        return [__('Property'), ...array_map(fn (ReportColumn $column): string => $column->label, ReportKey::BookingChanges->columns())];
    }

    /**
     * The values in the order of `csvHeadings()`.
     *
     * @return list<string|int|float>
     */
    public function csv(): array
    {
        $values = $this->change->values();

        return [$this->propertyName, ...array_map(fn (ReportColumn $column): string|int|float => $values[$column->key] ?? '', ReportKey::BookingChanges->columns())];
    }
}
