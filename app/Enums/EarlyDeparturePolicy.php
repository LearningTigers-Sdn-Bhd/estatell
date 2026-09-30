<?php

namespace App\Enums;

/**
 * What a rate plan charges when a guest leaves before the booked departure date.
 */
enum EarlyDeparturePolicy: string
{
    case ChargeFull = 'charge_full';
    case ChargeUsedNights = 'charge_used_nights';

    public function label(): string
    {
        return match ($this) {
            self::ChargeFull => __('Charge the full stay'),
            self::ChargeUsedNights => __('Charge only the nights used'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $policy): array => ['value' => $policy->value, 'label' => $policy->label()],
            self::cases(),
        );
    }
}
