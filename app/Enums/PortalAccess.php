<?php

namespace App\Enums;

/**
 * Whether companies can book a rate plan in the company portal: not at all, every company, or only the companies
 * linked to the plan (Selected is only for derived plans). See docs/23-company-portal/01-company-portal.md.
 */
enum PortalAccess: string
{
    case Hidden = 'hidden';
    case All = 'all';
    case Selected = 'selected';

    public function label(): string
    {
        return match ($this) {
            self::Hidden => __('Hidden'),
            self::All => __('All companies'),
            self::Selected => __('Selected companies'),
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
