<?php

namespace App\Data\BookingEngine;

use App\Models\BookingReturnAddress;
use Spatie\LaravelData\Data;

/**
 * One approved return address on the API tab.
 */
class BookingReturnAddressData extends Data
{
    public function __construct(
        public int $id,
        public string $url,
    ) {}

    public static function fromModel(BookingReturnAddress $address): self
    {
        return new self($address->id, $address->url);
    }
}
