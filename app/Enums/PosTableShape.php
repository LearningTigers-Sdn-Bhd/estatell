<?php

namespace App\Enums;

enum PosTableShape: string
{
    case Square = 'square';
    case Round = 'round';
    case Rect = 'rect';

    public function label(): string
    {
        return match ($this) {
            self::Square => __('Square'),
            self::Round => __('Round'),
            self::Rect => __('Rectangle'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
