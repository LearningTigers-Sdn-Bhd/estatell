<?php

namespace App\Enums;

enum PosStocktakeStatus: string
{
    case Draft = 'draft';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Counting'),
            self::Closed => __('Closed'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
