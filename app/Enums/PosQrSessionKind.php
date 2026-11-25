<?php

namespace App\Enums;

enum PosQrSessionKind: string
{
    case Table = 'table';
    case Room = 'room';

    public function label(): string
    {
        return match ($this) {
            self::Table => __('Table'),
            self::Room => __('Room'),
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
