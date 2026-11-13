<?php

namespace App\Enums;

/**
 * Whether guests can park at the property, shown on the Concierge page.
 */
enum ParkingAvailability: string
{
    case None = 'none';
    case OnSite = 'on_site';
    case Nearby = 'nearby';

    public function label(): string
    {
        return match ($this) {
            self::None => __('No parking'),
            self::OnSite => __('On-site'),
            self::Nearby => __('Nearby'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
