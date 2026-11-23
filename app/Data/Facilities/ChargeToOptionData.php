<?php

namespace App\Data\Facilities;

use Spatie\LaravelData\Data;

/**
 * One choice of the Charge to select of a reservation's booking: `f{folio id}` for an Open folio, or `u{unit id}`
 * for a unit with no folio yet ("(new folio)", created on save).
 * See docs/33-facilities/04-facility-bookings.md#charge-to.
 */
class ChargeToOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}
}
