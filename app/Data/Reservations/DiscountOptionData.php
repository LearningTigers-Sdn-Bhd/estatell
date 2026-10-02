<?php

namespace App\Data\Reservations;

use App\Models\Discount;
use Spatie\LaravelData\Data;

/**
 * A RoomOnly discount to pick on a booking line.
 */
class DiscountOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public static function fromModel(Discount $discount): self
    {
        return new self($discount->id, $discount->name);
    }
}
