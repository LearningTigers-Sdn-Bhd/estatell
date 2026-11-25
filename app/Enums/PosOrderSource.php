<?php

namespace App\Enums;

enum PosOrderSource: string
{
    case Terminal = 'terminal';
    case TableQr = 'table_qr';
    case RoomQr = 'room_qr';

    public function label(): string
    {
        return match ($this) {
            self::Terminal => __('Terminal'),
            self::TableQr => __('Table QR'),
            self::RoomQr => __('Room QR'),
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
