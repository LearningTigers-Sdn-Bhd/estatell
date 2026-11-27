<?php

namespace App\Enums;

/**
 * Which way a land transfer goes: in to the property on the arrival date, or out on the departure date.
 */
enum TransferDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => __('Transfer in'),
            self::Out => __('Transfer out'),
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
