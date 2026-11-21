<?php

namespace App\Enums;

/**
 * The kinds of booking remark, each for the team that acts on it. A booking has at most one remark of each kind.
 * See docs/12-reservations/16-remarks.md.
 */
enum RemarkKind: string
{
    case Housekeeping = 'housekeeping';
    case Dietary = 'dietary';
    case Package = 'package';
    case Payment = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::Housekeeping => __('Housekeeping remarks'),
            self::Dietary => __('Dietary remarks'),
            self::Package => __('Package remarks'),
            self::Payment => __('Payment remarks'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $kind): array => ['value' => $kind->value, 'label' => $kind->label()],
            self::cases(),
        );
    }
}
