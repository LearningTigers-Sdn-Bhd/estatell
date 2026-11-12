<?php

namespace App\Enums;

/**
 * Why a tax is not charged for one reservation unit. See docs/06-catalogues/03-taxes.md#tax-waivers.
 */
enum TaxWaiverReason: string
{
    case PaidElsewhere = 'paid_elsewhere';
    case GuestReceipt = 'guest_receipt';

    public function label(): string
    {
        return match ($this) {
            self::PaidElsewhere => __('Someone else collected it'),
            self::GuestReceipt => __('Guest has a receipt'),
        };
    }

    /**
     * True when the waiver names who collected the tax (`paid_to`).
     */
    public function needsPaidTo(): bool
    {
        return $this === self::PaidElsewhere;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
            self::cases(),
        );
    }
}
