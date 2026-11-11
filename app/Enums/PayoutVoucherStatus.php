<?php

namespace App\Enums;

/**
 * Where a payout voucher stands. A paid or carried-forward voucher never changes.
 * See docs/30-commission-fees/04-payout-vouchers.md#flow.
 */
enum PayoutVoucherStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';
    case CarriedForward = 'carried_forward';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Approved => __('Approved'),
            self::Paid => __('Paid'),
            self::CarriedForward => __('Carried forward'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Draft => Tone::Warning,
            self::Approved => Tone::Info,
            self::Paid => Tone::Success,
            self::CarriedForward => Tone::Neutral,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }

    /**
     * Whether the property sees the voucher on its Payouts page. A Draft can still change, so only Estatell sees it.
     */
    public function isVisibleToProperty(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * @return list<self>
     */
    public static function visibleToProperty(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isVisibleToProperty()));
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Draft => [self::Approved, self::CarriedForward],
            self::Approved => [self::Paid],
            self::Paid, self::CarriedForward => [],
        }, true);
    }
}
