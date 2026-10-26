<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * Where the booking form's back arrow and Cancel go: the page that opened the form. `query` holds the `back` and
 * `back_query` values to keep when Reservation mode switches pages, and is empty for the Reservations default.
 */
class BookingBackData extends Data
{
    /**
     * @param  array<string, string>  $query
     */
    public function __construct(
        public string $href,
        public string $label,
        public array $query,
    ) {}
}
