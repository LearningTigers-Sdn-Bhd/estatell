<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOrderLineTax;
use App\Models\TransactionCode;
use App\Services\Pos\Calculation\PosCalculatorContext;
use App\Services\Pos\Calculation\PosCalculatorDiscount;
use App\Services\Pos\Calculation\PosCalculatorLine;
use App\Services\Pos\Calculation\PosCalculatorModifier;
use App\Services\Pos\Calculation\PosCalculatorResult;
use App\Services\Pos\Calculation\PosCalculatorTax;
use App\Services\Pos\PosOrderCalculator;
use App\Services\Pos\PosTaxResolver;
use App\Services\Reservations\UnitNationality;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Price an open order again and save the result: each line's gross, discount, net, service charge, tax and total, its tax
 * rows, and the order totals. `PosOrderCalculator` does the maths and is the only source of it. The packaging fee line is
 * kept in step with the dining option (one system line while the order has items and the option has a fee). When the
 * calculator refuses (`sst_registration_missing`, `nationality_required`, `inclusive_tax_exceeds_amount`), or a code the
 * order needs is missing (`service_charge_code_required`, `packaging_code_required`), nothing is saved and the refusal is
 * returned, so an order never holds figures that were not checked. Call it inside the caller's transaction, with the
 * order locked. A settled order is never priced again.
 */
class RecalculatePosOrder
{
    public function __construct(
        private PosOrderCalculator $calculator,
        private PosTaxResolver $taxResolver,
        private UnitNationality $unitNationality,
    ) {}

    public function __invoke(PosOrder $order): PosCalculatorResult
    {
        $order->loadMissing(['outlet', 'diningOption', 'reservationUnit']);
        $outlet = $order->outlet;
        $dining = $order->diningOption;
        $property = $outlet->property;

        if ($dining->apply_service_charge && $outlet->service_charge_transaction_code_id === null) {
            return PosCalculatorResult::refused('service_charge_code_required');
        }

        $fee = BigDecimal::of($dining->packaging_fee);

        if ($fee->isPositive() && $outlet->packaging_transaction_code_id === null) {
            return PosCalculatorResult::refused('packaging_code_required');
        }

        $lines = $order->lines()->where('kitchen_status', '!=', PosKitchenStatus::Voided->value)->with('modifiers')->orderBy('id')->get();
        $itemLines = $lines->filter(fn (PosOrderLine $line): bool => $line->line_kind !== PosOrderLineKind::PackagingFee);
        $packaging = $this->syncPackagingLine($order, $lines, $itemLines->isNotEmpty() && $fee->isPositive() ? $dining->packaging_fee : null);

        $taxCache = [];
        $taxesOf = function (int $codeId) use (&$taxCache, $property): array {
            return $taxCache[$codeId] ??= $this->taxResolver->forTransactionCode($property, $codeId);
        };

        $calculatorLines = $itemLines->map(fn (PosOrderLine $line): PosCalculatorLine => new PosCalculatorLine(
            key: $line->id,
            unitPrice: $line->unit_price,
            quantity: (int) $line->quantity,
            priceIncludesTax: $line->price_includes_tax,
            taxes: $taxesOf($line->transaction_code_id),
            serviceChargeExempt: $line->service_charge_exempt,
            isEntitlementCovered: $line->is_entitlement_covered,
            modifiers: array_values($line->modifiers->map(fn ($modifier): PosCalculatorModifier => new PosCalculatorModifier($modifier->charged_price, $modifier->quantity))->all()),
            discount: $line->discount_type === null ? null : new PosCalculatorDiscount($line->discount_type, (string) $line->discount_value),
            excludeAddonsFromDiscount: $line->exclude_addons_from_discount,
        ))->all();
        $calculatorLines = array_values($calculatorLines);

        $orderDiscount = $order->discounts()->orderByDesc('id')->first();
        $unit = $order->reservationUnit;

        $context = new PosCalculatorContext(
            applyServiceCharge: $dining->apply_service_charge,
            serviceChargeRate: $dining->service_charge_rate,
            serviceChargeTaxable: $dining->service_charge_taxable,
            packagingFee: $packaging === null ? '0.00' : $dining->packaging_fee,
            packagingTaxes: $packaging === null ? [] : $taxesOf($packaging->transaction_code_id),
            orderDiscount: $orderDiscount === null ? null : new PosCalculatorDiscount($orderDiscount->discount_type, $orderDiscount->value),
            sstRegistered: $this->taxResolver->sstRegistrationNumber($outlet, $property) !== '',
            guestIsForeign: $unit === null ? null : $this->guestIsForeign($this->unitNationality->for($unit)),
        );

        $result = $this->calculator->calculate($calculatorLines, $context);

        if ($result->isRefused()) {
            return $result;
        }

        $this->save($order, $result, $packaging);

        return $result;
    }

    private function guestIsForeign(?string $nationalityCode): ?bool
    {
        return $nationalityCode === null ? null : strtoupper($nationalityCode) !== 'MY';
    }

    /**
     * Keep the one system packaging line in step: created or changed to the fee, or removed (with its tax rows) when the
     * order has no items or the dining option has no fee.
     *
     * @param  Collection<int, PosOrderLine>  $lines
     */
    private function syncPackagingLine(PosOrder $order, Collection $lines, ?string $fee): ?PosOrderLine
    {
        $existing = $lines->first(fn (PosOrderLine $line): bool => $line->line_kind === PosOrderLineKind::PackagingFee);

        if ($fee === null) {
            if ($existing !== null) {
                PosOrderLineTax::query()->where('pos_order_line_id', $existing->id)->delete();
                $existing->delete();
            }

            return null;
        }

        $line = $existing ?? new PosOrderLine;
        $line->property_id = $order->property_id;
        $line->pos_order_id = $order->id;
        $line->line_kind = PosOrderLineKind::PackagingFee;
        $line->price_includes_tax = false;
        $line->service_charge_exempt = true;
        $line->transaction_code_id = $order->outlet->packaging_transaction_code_id;
        $line->item_name = TransactionCode::query()->whereKey($order->outlet->packaging_transaction_code_id)->value('name') ?? 'Packaging';
        $line->variant_name = '';
        $line->quantity = '1.000';
        $line->unit_price = $fee;
        $line->kitchen_status = PosKitchenStatus::Fired;
        $line->save();

        return $line;
    }

    private function save(PosOrder $order, PosCalculatorResult $result, ?PosOrderLine $packaging): void
    {
        $discountTotal = BigDecimal::zero();

        foreach ($result->lines as $row) {
            $id = $row->isPackagingFee ? $packaging?->id : $row->key;
            $line = PosOrderLine::query()->whereKey($id)->firstOrFail();
            $line->forceFill([
                'line_gross' => $row->lineGross,
                'discount_amount' => $row->itemDiscount,
                'net_amount' => $row->netAmount,
                'service_charge_amount' => $row->serviceChargeAmount,
                'tax_amount' => $row->taxTotal,
                'line_total' => $row->lineTotal,
            ])->save();

            PosOrderLineTax::query()->where('pos_order_line_id', $line->id)->delete();

            foreach ($row->taxes as $tax) {
                $this->taxRow($line, $tax->tax, $tax->taxableBase, $tax->amount, $tax->inclusive);
            }

            $discountTotal = $discountTotal->plus($row->itemDiscount)->plus($row->orderDiscountShare);
        }

        $order->forceFill([
            'subtotal' => $result->subtotal,
            'discount_total' => $discountTotal->toScale(2)->__toString(),
            'service_charge_total' => $result->serviceChargeTotal,
            'tax_total' => $result->taxTotal,
            'grand_total' => $result->grandTotal,
        ])->save();
    }

    private function taxRow(PosOrderLine $line, PosCalculatorTax $tax, string $taxableBase, string $amount, bool $inclusive): void
    {
        $row = new PosOrderLineTax;
        $row->pos_order_line_id = $line->id;
        $row->tax_id = $tax->taxId;
        $row->transaction_code_id = $tax->transactionCodeId;
        $row->tax_code = $tax->code;
        $row->tax_name = $tax->name;
        $row->lhdn_tax_type = $tax->lhdnTaxType;
        $row->rate_type = $tax->rateType;
        $row->tax_rate = $tax->rate;
        $row->is_inclusive = $inclusive;
        $row->taxable_base = $taxableBase;
        $row->tax_amount = $amount;
        $row->save();
    }
}
