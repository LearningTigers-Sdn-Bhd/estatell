<?php

namespace App\Enums;

/**
 * Which nights a room revenue policy charges.
 */
enum PolicyScope: string
{
    case FirstNight = 'first_night';
    case WholeStay = 'whole_stay';
    case UnusedNights = 'unused_nights';
    case OneCharge = 'one_charge';

    public function label(): string
    {
        return match ($this) {
            self::FirstNight => __('First night'),
            self::WholeStay => __('Whole stay'),
            self::UnusedNights => __('Unused nights'),
            self::OneCharge => __('One charge'),
        };
    }

    /**
     * The scopes a policy code accepts. A code that is not a policy accepts none.
     *
     * @return list<self>
     */
    public static function allowedFor(SystemTransactionCode $code): array
    {
        return match ($code) {
            SystemTransactionCode::NoShowRevenue,
            SystemTransactionCode::CancellationRevenue => [self::FirstNight, self::WholeStay],
            SystemTransactionCode::EarlyDepartureRevenue => [self::UnusedNights],
            SystemTransactionCode::LateCheckoutRevenue => [self::OneCharge],
            default => [],
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
