<?php

namespace App\Enums;

enum Currency: string
{
    case Myr = 'MYR';

    public function label(): string
    {
        return match ($this) {
            self::Myr => __('Malaysian ringgit (MYR)'),
        };
    }

    /** The currency name as an amount in words starts with it, for example "Ringgit Malaysia". */
    public function wordsName(): string
    {
        return match ($this) {
            self::Myr => 'Ringgit Malaysia',
        };
    }

    /** The name of the minor unit (one hundredth), for example "sen". */
    public function minorUnitWords(): string
    {
        return match ($this) {
            self::Myr => 'sen',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
