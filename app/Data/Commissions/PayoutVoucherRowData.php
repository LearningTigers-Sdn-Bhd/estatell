<?php

namespace App\Data\Commissions;

use App\Data\Property\CurrencyData;
use App\Data\Shared\BadgeData;
use App\Models\PayoutVoucher;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

class PayoutVoucherRowData extends Data
{
    /** The relations {@see fromModel()} reads. */
    public const array RELATIONS = ['property:id,name,code', 'property.settings'];

    public function __construct(
        public int $id,
        public string $number,
        public int $propertyId,
        public string $propertyName,
        public string $periodStart,
        public string $periodEnd,
        public string $dueOn,
        public string $amount,
        /** True when the property owes Estatell. */
        public bool $owedToEstatell,
        public ?CurrencyData $currency,
        public BadgeData $status,
    ) {}

    /**
     * Expects the {@see RELATIONS} loaded.
     */
    public static function fromModel(PayoutVoucher $voucher): self
    {
        return new self(
            id: $voucher->id,
            number: $voucher->number,
            propertyId: $voucher->property_id,
            propertyName: $voucher->property->name,
            periodStart: $voucher->period_start->toDateString(),
            periodEnd: $voucher->period_end->toDateString(),
            dueOn: $voucher->due_on->toDateString(),
            amount: $voucher->amount,
            owedToEstatell: BigDecimal::of($voucher->amount)->isNegative(),
            currency: CurrencyData::forProperty($voucher->property),
            status: BadgeData::fromPayoutVoucherStatus($voucher->status),
        );
    }
}
