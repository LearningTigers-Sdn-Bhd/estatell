<?php

namespace App\Enums;

/**
 * What a guest gets as a room key at the self check-in kiosk, set per property.
 */
enum KioskKeyMode: string
{
    case Pin = 'pin';
    case Card = 'card';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Pin => __('PIN code'),
            self::Card => __('Key card'),
            self::Both => __('PIN code and key card'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label()], self::cases());
    }
}
