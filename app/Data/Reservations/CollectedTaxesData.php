<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * Who pays the taxes collected at check-in on a booking with a company, for the **Taxes at check-in** row of the
 * summary and the Edit group Dialog. `companyPays` is the answer: the booking's own choice (`bookingPayer`, a
 * `CollectedTaxesPayer` value) when set, else the company's default (`companyDefaultPays`,
 * `companies.pays_collected_taxes`). `taxes` are the names of the property's taxes with **Collect at check-in** on.
 * See docs/12-reservations/02-group-bookings.md#taxes-at-check-in.
 */
class CollectedTaxesData extends Data
{
    /**
     * @param  list<string>  $taxes
     */
    public function __construct(
        public bool $companyPays,
        public ?string $companyName,
        public array $taxes,
        public bool $companyDefaultPays = false,
        public ?string $bookingPayer = null,
    ) {}
}
