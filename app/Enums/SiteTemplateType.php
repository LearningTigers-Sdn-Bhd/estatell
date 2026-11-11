<?php

namespace App\Enums;

/**
 * Who can pick a booking website template: every Microsite property (Standard) or one property (Custom).
 */
enum SiteTemplateType: string
{
    case Standard = 'standard';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Standard => __('Standard'),
            self::Custom => __('Custom'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
