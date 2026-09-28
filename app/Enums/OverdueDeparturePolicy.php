<?php

namespace App\Enums;

enum OverdueDeparturePolicy: string
{
    case ExtendOneNight = 'extend_one_night';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::ExtendOneNight => __('Extend one night'),
            self::Block => __('Block departure'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
