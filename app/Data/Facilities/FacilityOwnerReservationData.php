<?php

namespace App\Data\Facilities;

use Spatie\LaravelData\Data;

/**
 * A live reservation in the Timeline's Book facility search. `label` is the booker and the reference
 * ("Lee Wei · ES-2041"); `detail` is its rooms and its dates ("Deluxe · 101 · 18–21 Oct").
 */
class FacilityOwnerReservationData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
        public string $detail,
    ) {}
}
