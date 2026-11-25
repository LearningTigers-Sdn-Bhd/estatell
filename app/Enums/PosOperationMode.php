<?php

namespace App\Enums;

enum PosOperationMode: string
{
    case PayFirst = 'pay_first';
    case PayLater = 'pay_later';

    public function label(): string
    {
        return match ($this) {
            self::PayFirst => __('Pay first'),
            self::PayLater => __('Pay later'),
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
