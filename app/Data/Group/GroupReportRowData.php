<?php

namespace App\Data\Group;

use Spatie\LaravelData\Data;

/**
 * One row of a property report run for the whole group: the property, and the report row's display text by column
 * key. A total row has `total` true (one per property, then All properties with `code` null). `reservationId` is set on
 * a booking row of a front desk list (Arrivals, Departures, In house, Checked out) where the role may view
 * reservations, so the row opens the group reservation panel.
 */
class GroupReportRowData extends Data
{
    /**
     * @param  array<string, string>  $cells
     */
    public function __construct(
        public ?string $code,
        public string $property,
        public array $cells,
        public bool $total = false,
        public ?int $reservationId = null,
    ) {}
}
