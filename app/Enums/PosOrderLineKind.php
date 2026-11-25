<?php

namespace App\Enums;

enum PosOrderLineKind: string
{
    case Item = 'item';
    case ComboComponent = 'combo_component';
    case PackagingFee = 'packaging_fee';

    public function label(): string
    {
        return match ($this) {
            self::Item => __('Item'),
            self::ComboComponent => __('Combo item'),
            self::PackagingFee => __('Packaging fee'),
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
