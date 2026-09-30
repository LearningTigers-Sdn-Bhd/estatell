<?php

namespace App\Enums;

/**
 * How a per-guest rate plan prices one age group: free, a percent of the 1-adult price, or a fixed amount.
 */
enum AgeBandPricing: string
{
    case Free = 'free';
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Free => __('Free'),
            self::Percent => __('Percent of adult price'),
            self::Fixed => __('Fixed amount'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $pricing): array => ['value' => $pricing->value, 'label' => $pricing->label()],
            self::cases(),
        );
    }
}
