<?php

namespace App\Data\Reservations;

use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\SwatchColor;
use App\Models\BookingSource;
use Spatie\LaravelData\Data;

/**
 * What `BookingSourceBadge` draws: the source name (the badge's accessible name), its type, and either an icon or
 * one or two letters on a swatch color. Exactly one of `icon` and `letters` is set.
 */
class BookingSourceBadgeData extends Data
{
    public function __construct(
        public string $name,
        public BookingSourceType $type,
        public ?BookingSourceIcon $icon,
        public ?string $letters,
        public SwatchColor $color,
    ) {}

    public static function fromModel(BookingSource $source): self
    {
        return new self(
            name: $source->name,
            type: $source->type,
            icon: $source->badge_icon,
            letters: $source->badge_letters,
            color: $source->badge_color,
        );
    }
}
