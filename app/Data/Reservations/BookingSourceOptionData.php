<?php

namespace App\Data\Reservations;

use App\Enums\BookingSourceType;
use App\Models\BookingSource;
use Spatie\LaravelData\Data;

/**
 * One booking source in the booking form's Source select and the reservations list filter.
 */
class BookingSourceOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public BookingSourceType $type,
        public string $typeLabel,
        public bool $isActive,
    ) {}

    public static function fromModel(BookingSource $source): self
    {
        return new self(
            id: $source->id,
            name: $source->name,
            type: $source->type,
            typeLabel: $source->type->label(),
            isActive: $source->is_active,
        );
    }
}
