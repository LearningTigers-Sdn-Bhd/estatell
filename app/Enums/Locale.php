<?php

namespace App\Enums;

enum Locale: string
{
    case English = 'en';
    case Malay = 'ms';

    public function label(): string
    {
        return match ($this) {
            self::English => __('English'),
            self::Malay => __('Bahasa Malaysia'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
