<?php

namespace App\Enums;

/**
 * Who pays the charges of a reservation. See docs/12-reservations/02-group-bookings.md.
 */
enum BillingMode: string
{
    case MasterPays = 'master_pays';
    case MasterPaysAll = 'master_pays_all';
    case EachPays = 'each_pays';

    public function label(): string
    {
        return match ($this) {
            self::MasterPays => __('Master folio pays'),
            self::MasterPaysAll => __('Master folio pays all'),
            self::EachPays => __('Each unit pays'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
            self::cases(),
        );
    }
}
