<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The server's answer for one booking line: the priced nights, the totals, the restriction warnings and how many units
 * are free. `lineTotal` is the rooms of the whole line and `addOnsTotal` its add-ons, both times the beds. `isOverridden`
 * is true when `unitTotal` is a price override and not the quote. When the line cannot be priced, `errors` holds the refusal keyed by form field and the rest is empty.
 */
class BookingQuoteData extends Data
{
    /**
     * @param  list<BookingQuoteNightData>  $nights
     * @param  list<string>  $violations
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public array $nights,
        public ?string $unitTotal,
        public ?string $lineTotal,
        public string $addOnsTotal,
        public bool $isOverridden,
        public int $beds,
        public array $violations,
        public int $available,
        public array $errors,
    ) {}

    /**
     * @param  array<string, string>  $errors
     */
    public static function refused(array $errors, int $beds = 1): self
    {
        return new self([], null, null, '0.00', false, $beds, [], 0, $errors);
    }
}
