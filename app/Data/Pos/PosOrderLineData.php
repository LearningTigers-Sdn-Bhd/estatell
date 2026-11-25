<?php

namespace App\Data\Pos;

use App\Enums\PosKitchenStatus;
use App\Models\PosOrderLine;
use Spatie\LaravelData\Data;

/**
 * One order line on the ticket or receipt. `refundableQuantity` is what a refund can still take back. The server decides every amount.
 */
class PosOrderLineData extends Data
{
    /**
     * @param  list<string>  $modifiers
     */
    public function __construct(
        public int $id,
        public string $itemName,
        public string $variantName,
        public string $quantity,
        public string $unitPrice,
        public array $modifiers,
        public ?string $note,
        public string $lineGross,
        public string $discountAmount,
        public string $netAmount,
        public string $serviceChargeAmount,
        public string $taxAmount,
        public string $lineTotal,
        public string $kitchenStatus,
        public bool $isDraft,
        public bool $isVoided,
        public bool $isEntitlementCovered,
        public bool $isPackagingFee,
        public string $refundableQuantity,
        public int $courseNumber = 1,
        public ?int $seatNumber = null,
    ) {}

    public static function fromModel(PosOrderLine $line, ?string $refundedQuantity = null): self
    {
        $status = $line->kitchen_status;

        return new self(
            $line->id,
            $line->item_name,
            $line->variant_name,
            (string) $line->quantity,
            $line->unit_price,
            array_values($line->modifiers->map(fn ($modifier): string => ($modifier->quantity > 1 ? $modifier->quantity.'× ' : '').$modifier->option_name)->all()),
            $line->note,
            $line->line_gross,
            $line->discount_amount,
            $line->net_amount,
            $line->service_charge_amount,
            $line->tax_amount,
            $line->line_total,
            $status->value,
            $status === PosKitchenStatus::Draft || $status === PosKitchenStatus::Held,
            $status === PosKitchenStatus::Voided,
            $line->is_entitlement_covered,
            $line->line_kind->value === 'packaging_fee',
            (string) max(0, (float) $line->quantity - (float) ($refundedQuantity ?? 0)),
            $line->course_number,
            $line->seat_number,
        );
    }
}
