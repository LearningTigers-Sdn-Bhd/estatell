<?php

namespace App\Data\BookingEngine;

use Spatie\LaravelData\Data;

/**
 * One row of "Before you publish". `detail` says what is missing and is empty when the row is done.
 */
class BookingSiteChecklistItemData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $done,
        public ?string $detail,
    ) {}
}
