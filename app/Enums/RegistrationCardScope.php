<?php

namespace App\Enums;

enum RegistrationCardScope: string
{
    case PrimaryOnly = 'primary_only';
    case EveryAdult = 'every_adult';

    public function label(): string
    {
        return match ($this) {
            self::PrimaryOnly => __('Primary guest only'),
            self::EveryAdult => __('Every adult'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
