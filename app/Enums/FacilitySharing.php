<?php

namespace App\Enums;

/**
 * How bookings use a facility: one booking takes all of a Private facility; Shared ones take bookings until full.
 * See docs/33-facilities/01-overview.md.
 */
enum FacilitySharing: string
{
    case Private = 'private';
    case Shared = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::Private => __('Private'),
            self::Shared => __('Shared'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Private => __('One booking takes the whole facility.'),
            self::Shared => __('Several bookings share it until it is full.'),
        };
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $sharing): array => ['value' => $sharing->value, 'label' => $sharing->label(), 'description' => $sharing->description()],
            self::cases(),
        );
    }
}
