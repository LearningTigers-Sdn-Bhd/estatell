<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * Who pays the taxes collected at check-in on a booking with a company, for the **Taxes at check-in** row of the
 * summary: the company when it pays them (`companies.pays_collected_taxes`), else the guest. `taxes` are the names of
 * the property's taxes with **Collect at check-in** on. See docs/12-reservations/02-group-bookings.md#taxes-at-check-in.
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
    ) {}
}
