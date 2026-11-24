<?php

namespace App\Enums;

enum PosTenderType: string
{
    case Cash = 'cash';
    case Card = 'card';
    case EWallet = 'e_wallet';
    case DuitNowQr = 'duitnow_qr';
    case PaymentVoucher = 'payment_voucher';
    case RoomCharge = 'room_charge';

    public function label(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::Card => __('Card'),
            self::EWallet => __('E-wallet'),
            self::DuitNowQr => __('DuitNow QR'),
            self::PaymentVoucher => __('Payment voucher'),
            self::RoomCharge => __('Charge to room'),
        };
    }

    /**
     * Whether an outlet can offer this tender at the till. Room charge is chosen from the guest list, not set up per outlet.
     */
    public function isOutletTender(): bool
    {
        return $this !== self::RoomCharge;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function outletOptions(): array
    {
        return array_values(array_filter(self::options(), fn (array $option): bool => self::from($option['value'])->isOutletTender()));
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
