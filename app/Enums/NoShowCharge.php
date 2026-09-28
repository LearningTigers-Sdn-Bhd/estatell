<?php

namespace App\Enums;

enum NoShowCharge: string
{
    case FirstNight = 'first_night';
    case FullStay = 'full_stay';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::FirstNight => __('First night'),
            self::FullStay => __('Full stay'),
            self::None => __('No charge'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
