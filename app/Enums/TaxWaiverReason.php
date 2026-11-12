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
            self::PaidElsewhere => __('OTA or agent'),
            self::GuestReceipt => __('Guest receipt'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PaidElsewhere => __('An OTA, agent or company collected the tax and declares it.'),
            self::GuestReceipt => __('The guest paid the tax elsewhere and shows the receipt.'),
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
