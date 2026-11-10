<?php

namespace App\Enums;

enum SailingKind: string
{
    case Guests = 'guests';
    case Job = 'job';

    public function label(): string
    {
        return match ($this) {
            self::Guests => __('Guest trip'),
            self::Job => __('Boat job'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $kind): array => ['value' => $kind->value, 'label' => $kind->label()],
            self::cases(),
        );
    }
}
