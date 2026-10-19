<?php

namespace App\Enums;

/**
 * Who can use a rate plan: everyone (a normal plan), every company, or only the companies listed on the plan
 * (Selected is only for derived plans). See docs/08-rates/01-rate-plans.md#who-can-use-this-rate.
 */
enum CompanyAccess: string
{
    case Everyone = 'everyone';
    case All = 'all';
    case Selected = 'selected';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => __('Everyone'),
            self::All => __('All companies'),
            self::Selected => __('Only these companies'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $access): array => ['value' => $access->value, 'label' => $access->label()],
            self::cases(),
        );
    }
}
