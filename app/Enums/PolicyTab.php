<?php

namespace App\Enums;

/**
 * The policy tabs of the Room revenue page. The value is the URL segment.
 */
enum PolicyTab: string
{
    case NoShow = 'no-show';
    case Cancellation = 'cancellation';
    case EarlyDeparture = 'early-departure';
    case LateCheckout = 'late-checkout';

    public function code(): SystemTransactionCode
    {
        return match ($this) {
            self::NoShow => SystemTransactionCode::NoShowRevenue,
            self::Cancellation => SystemTransactionCode::CancellationRevenue,
            self::EarlyDeparture => SystemTransactionCode::EarlyDepartureRevenue,
            self::LateCheckout => SystemTransactionCode::LateCheckoutRevenue,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NoShow => __('No-show'),
            self::Cancellation => __('Cancellation'),
            self::EarlyDeparture => __('Early departure'),
            self::LateCheckout => __('Late check-out'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public function scopeOptions(): array
    {
        return array_map(
            fn (PolicyScope $scope): array => ['value' => $scope->value, 'label' => $scope->label()],
            PolicyScope::allowedFor($this->code()),
        );
    }
}
