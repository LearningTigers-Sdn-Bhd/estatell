<?php

namespace App\Services\Pos;

use App\Enums\PosDiscountType;
use App\Enums\TaxRateType;
use App\Services\EInvoice\LhdnTaxType;
use App\Services\Pos\Calculation\PosCalculatorContext;
use App\Services\Pos\Calculation\PosCalculatorDiscount;
use App\Services\Pos\Calculation\PosCalculatorLine;
use App\Services\Pos\Calculation\PosCalculatorLineResult;
use App\Services\Pos\Calculation\PosCalculatorModifier;
use App\Services\Pos\Calculation\PosCalculatorResult;
use App\Services\Pos\Calculation\PosCalculatorRow;
use App\Services\Pos\Calculation\PosCalculatorTax;
use App\Services\Pos\Calculation\PosCalculatorTaxResult;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The only place a POS order is priced: net, service charge and tax per line, and the order totals, to the sen. Nothing
 * else re-runs tax on a POS sale, so the receipt, the folio, the daily journal and the e-invoice always agree.
 *
 * Money is `BigDecimal` rounded half up to two places. Whether a price already holds its tax is decided by the item
 * (`priceIncludesTax`), never by the tax row. Service charge is outside the SST base unless the dining option says it is
 * taxable (RMCD Service Tax Policy No. 3/2024, Amendment No. 1). Steps, in order:
 *
 * 1. line gross: an entitlement-covered line costs nothing but its paid modifiers;
 * 2. item discount, before service charge and tax;
 * 3. the order discount, shared out in whole sen by the largest-remainder method, never negative on any line;
 * 4. per line, `++` pricing adds service charge and tax on top, and nett pricing takes them out of the displayed price,
 *    with the last sen put where it cannot make a tax negative.
 *
 * It refuses with `sst_registration_missing`, `nationality_required` or `inclusive_tax_exceeds_amount` instead of
 * charging a wrong amount. It reads nothing from the database. See docs/34-native-pos/04-tax-checkout-and-pms.md.
 */
class PosOrderCalculator
{
    /**
     * The key of the packaging fee line, which sorts after every real line.
     */
    public const int PACKAGING_KEY = PHP_INT_MAX;

    /**
     * @param  list<PosCalculatorLine>  $lines  The order's lines that are not voided.
     */
    public function calculate(array $lines, PosCalculatorContext $context): PosCalculatorResult
    {
        $rows = [];

        foreach ($lines as $line) {
            $rows[$line->key] = $this->grossRow($line);
        }

        $this->shareOrderDiscount($rows, $context->orderDiscount);

        $serviceChargeRate = $context->applyServiceCharge ? $this->percent($context->serviceChargeRate) : BigDecimal::zero();
        $packagingFee = $this->money(BigDecimal::of($context->packagingFee));
        $results = [];

        foreach ($rows as $row) {
            $result = $this->priceLine($row->line, $row->payable, $row, $serviceChargeRate, $context);

            if (is_string($result)) {
                return PosCalculatorResult::refused($result);
            }

            $results[] = $result;
        }

        if ($packagingFee->isPositive()) {
            $packaging = new PosCalculatorLine(self::PACKAGING_KEY, $packagingFee->__toString(), 1, false, $context->packagingTaxes, serviceChargeExempt: true);
            $packagingRow = new PosCalculatorRow($packaging, $packagingFee, $this->money(BigDecimal::zero()), $this->money(BigDecimal::zero()), $packagingFee);
            $result = $this->priceLine($packaging, $packagingFee, $packagingRow, BigDecimal::zero(), $context, true);

            if (is_string($result)) {
                return PosCalculatorResult::refused($result);
            }

            $results[] = $result;
        }

        $subtotal = $this->sum(array_map(fn (PosCalculatorLineResult $line): string => $line->netAmount, $results));
        $serviceCharge = $this->sum(array_map(fn (PosCalculatorLineResult $line): string => $line->serviceChargeAmount, $results));
        $tax = $this->sum(array_map(fn (PosCalculatorLineResult $line): string => $line->taxTotal, $results));

        return PosCalculatorResult::calculated(
            $results,
            $subtotal->__toString(),
            $serviceCharge->__toString(),
            $tax->__toString(),
            $this->money($subtotal->plus($serviceCharge)->plus($tax))->__toString(),
        );
    }

    private function grossRow(PosCalculatorLine $line): PosCalculatorRow
    {
        $quantity = BigDecimal::of($line->quantity);
        $baseGross = $line->isEntitlementCovered ? BigDecimal::zero() : BigDecimal::of($line->unitPrice)->multipliedBy($quantity);
        $addonGross = array_reduce(
            $line->modifiers,
            fn (BigDecimal $total, PosCalculatorModifier $modifier): BigDecimal => $total->plus(BigDecimal::of($modifier->chargedPrice)->multipliedBy($modifier->quantity)),
            BigDecimal::zero(),
        )->multipliedBy($quantity);
        $lineGross = $this->money($baseGross->plus($addonGross));
        $discountable = $line->excludeAddonsFromDiscount ? $this->money($baseGross) : $lineGross;
        $itemDiscount = $line->discount === null ? $this->money(BigDecimal::zero()) : $this->discountOf($line->discount, $discountable);

        return new PosCalculatorRow($line, $lineGross, $itemDiscount, $this->money(BigDecimal::zero()), $this->money($lineGross->minus($itemDiscount)));
    }

    /**
     * Take the order discount off the lines that still cost something, whole sen at a time. Each line first gets the
     * floor of its exact share, then the sen left over go to the lines with the biggest fractions, ties to the lower key.
     * No line can go below zero, and the shares add up to the discount exactly.
     *
     * @param  array<int, PosCalculatorRow>  $rows
     */
    private function shareOrderDiscount(array &$rows, ?PosCalculatorDiscount $discount): void
    {
        if ($discount === null) {
            return;
        }

        $eligible = array_filter($rows, fn (PosCalculatorRow $row): bool => $row->payable->isPositive());
        $total = $this->sum(array_map(fn (PosCalculatorRow $row): string => $row->payable->__toString(), $eligible));

        if (! $total->isPositive()) {
            return;
        }

        $amount = $this->discountOf($discount, $total);

        if (! $amount->isPositive()) {
            return;
        }

        $floors = [];
        $fractions = [];

        foreach ($eligible as $key => $row) {
            $exact = $amount->multipliedBy($row->payable)->dividedBy($total, 12, RoundingMode::HalfUp);
            $floors[$key] = $exact->toScale(2, RoundingMode::Down);
            $fractions[$key] = $exact->minus($floors[$key]);
        }

        $leftSen = $amount->minus($this->sum(array_map(fn (BigDecimal $floor): string => $floor->__toString(), $floors)))->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();

        $order = array_keys($fractions);
        usort($order, fn (int $a, int $b): int => $fractions[$b]->compareTo($fractions[$a]) ?: $a <=> $b);

        foreach (array_slice($order, 0, max(0, $leftSen)) as $key) {
            $floors[$key] = $floors[$key]->plus('0.01');
        }

        foreach ($floors as $key => $share) {
            $share = $share->isGreaterThan($rows[$key]->payable) ? $rows[$key]->payable : $share;
            $rows[$key]->share = $this->money($share);
            $rows[$key]->payable = $this->money($rows[$key]->payable->minus($share));
        }
    }

    private function priceLine(PosCalculatorLine $line, BigDecimal $payable, PosCalculatorRow $row, BigDecimal $serviceChargeRate, PosCalculatorContext $context, bool $isPackaging = false): PosCalculatorLineResult|string
    {
        $taxes = [];

        foreach ($line->taxes as $tax) {
            if ($tax->foreignGuestsOnly && $payable->isPositive()) {
                if ($context->guestIsForeign === null) {
                    return 'nationality_required';
                }

                if (! $context->guestIsForeign) {
                    continue;
                }
            }

            $taxes[] = $tax;
        }

        if ($payable->isPositive() && ! $context->sstRegistered && array_any($taxes, fn (PosCalculatorTax $tax): bool => $tax->lhdnTaxType === LhdnTaxType::SERVICE_TAX)) {
            return 'sst_registration_missing';
        }

        $lineRate = $line->serviceChargeExempt ? BigDecimal::zero() : $serviceChargeRate;
        $quantity = BigDecimal::of($line->quantity);

        $calculated = $line->priceIncludesTax
            ? $this->nett($payable, $quantity, $taxes, $lineRate, $context->serviceChargeTaxable)
            : $this->exclusive($payable, $quantity, $taxes, $lineRate, $context->serviceChargeTaxable);

        if (is_string($calculated)) {
            return $calculated;
        }

        [$net, $serviceCharge, $taxResults] = $calculated;
        $taxTotal = $this->sum(array_map(fn (PosCalculatorTaxResult $result): string => $result->amount, $taxResults));

        return new PosCalculatorLineResult(
            key: $line->key,
            isPackagingFee: $isPackaging,
            lineGross: $row->lineGross->__toString(),
            itemDiscount: $row->itemDiscount->__toString(),
            orderDiscountShare: $row->share->__toString(),
            payableBase: $payable->__toString(),
            netAmount: $net->__toString(),
            serviceChargeAmount: $serviceCharge->__toString(),
            taxes: $taxResults,
            taxTotal: $taxTotal->__toString(),
            lineTotal: $this->money($net->plus($serviceCharge)->plus($taxTotal))->__toString(),
        );
    }

    /**
     * `++` pricing: the payable base is the net, and service charge and tax go on top.
     *
     * @param  list<PosCalculatorTax>  $taxes
     * @return array{0: BigDecimal, 1: BigDecimal, 2: list<PosCalculatorTaxResult>}
     */
    private function exclusive(BigDecimal $payable, BigDecimal $quantity, array $taxes, BigDecimal $serviceChargeRate, bool $serviceChargeTaxable): array
    {
        $net = $this->money($payable);
        $serviceCharge = $this->money($net->multipliedBy($serviceChargeRate));
        $taxBase = $serviceChargeTaxable ? $net->plus($serviceCharge) : $net;
        $results = [];

        foreach ($taxes as $tax) {
            $isFlat = $tax->rateType === TaxRateType::Flat;
            $amount = $isFlat ? $this->money(BigDecimal::of($tax->rate)->multipliedBy($quantity)) : $this->percentOf($taxBase, $tax->rate);
            $results[] = new PosCalculatorTaxResult($tax, $isFlat ? '0.00' : $taxBase->__toString(), $amount->__toString(), false);
        }

        return [$net, $serviceCharge, $results];
    }

    /**
     * Nett pricing: the customer pays the displayed price, and net, service charge and tax are taken out of it. Flat taxes
     * come off first. The rest is split with one divisor, then the last sen is put in the last percentage tax, or in the net
     * when that would make the tax negative, so the parts always add back to the displayed price.
     *
     * @param  list<PosCalculatorTax>  $taxes
     * @return array{0: BigDecimal, 1: BigDecimal, 2: list<PosCalculatorTaxResult>}|string
     */
    private function nett(BigDecimal $payable, BigDecimal $quantity, array $taxes, BigDecimal $serviceChargeRate, bool $serviceChargeTaxable): array|string
    {
        $flat = [];
        $percent = [];

        foreach ($taxes as $tax) {
            if ($tax->rateType === TaxRateType::Flat) {
                $flat[] = [$tax, $this->money(BigDecimal::of($tax->rate)->multipliedBy($quantity))];
            } else {
                $percent[] = $tax;
            }
        }

        $flatTotal = array_reduce($flat, fn (BigDecimal $total, array $pair): BigDecimal => $total->plus($pair[1]), BigDecimal::zero());

        if ($flatTotal->isGreaterThan($payable)) {
            return 'inclusive_tax_exceeds_amount';
        }

        $remaining = $this->money($payable->minus($flatTotal));
        $percentRate = array_reduce($percent, fn (BigDecimal $total, PosCalculatorTax $tax): BigDecimal => $total->plus($this->percent($tax->rate)), BigDecimal::zero());
        $divisor = $serviceChargeTaxable
            ? BigDecimal::one()->plus($serviceChargeRate)->multipliedBy(BigDecimal::one()->plus($percentRate))
            : BigDecimal::one()->plus($serviceChargeRate)->plus($percentRate);

        $net = $remaining->dividedBy($divisor, 2, RoundingMode::HalfUp);
        $serviceCharge = $this->money($net->multipliedBy($serviceChargeRate));
        $taxBase = $serviceChargeTaxable ? $net->plus($serviceCharge) : $net;
        $amounts = array_map(fn (PosCalculatorTax $tax): BigDecimal => $this->percentOf($taxBase, $tax->rate), $percent);

        $remainder = $remaining->minus($net)->minus($serviceCharge)->minus(array_reduce($amounts, fn (BigDecimal $total, BigDecimal $amount): BigDecimal => $total->plus($amount), BigDecimal::zero()));

        if (! $remainder->isZero()) {
            $last = array_key_last($amounts);

            if ($last !== null && $amounts[$last]->plus($remainder)->isPositiveOrZero()) {
                $amounts[$last] = $amounts[$last]->plus($remainder);
            } else {
                if ($last !== null) {
                    $remainder = $remainder->plus($amounts[$last]);
                    $amounts[$last] = $this->money(BigDecimal::zero());
                }

                $net = $net->plus($remainder);
            }
        }

        $results = [];

        foreach ($flat as [$tax, $amount]) {
            $results[] = new PosCalculatorTaxResult($tax, '0.00', $amount->__toString(), true);
        }

        foreach ($percent as $index => $tax) {
            $results[] = new PosCalculatorTaxResult($tax, $taxBase->__toString(), $amounts[$index]->__toString(), true);
        }

        return [$this->money($net), $serviceCharge, $results];
    }

    private function discountOf(PosCalculatorDiscount $discount, BigDecimal $base): BigDecimal
    {
        $value = BigDecimal::of($discount->value);

        $amount = match (true) {
            $discount->type === PosDiscountType::Foc => $base,
            $discount->type === PosDiscountType::FixedPrice => $base->minus($value),
            $discount->type->isPercent() => $base->multipliedBy($value->isGreaterThan(100) ? BigDecimal::of(100) : $value)->dividedBy(100, 2, RoundingMode::HalfUp),
            default => $value,
        };

        if ($amount->isNegative()) {
            $amount = BigDecimal::zero();
        }

        return $this->money($amount->isGreaterThan($base) ? $base : $amount);
    }

    /**
     * A percent rate as a fraction, exact for rates of up to two places.
     */
    private function percent(string $rate): BigDecimal
    {
        return BigDecimal::of($rate)->dividedBy(100, 6, RoundingMode::HalfUp);
    }

    private function percentOf(BigDecimal $base, string $rate): BigDecimal
    {
        return $base->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HalfUp);
    }

    /**
     * @param  array<int|string, string>  $amounts
     */
    private function sum(array $amounts): BigDecimal
    {
        return $this->money(array_reduce($amounts, fn (BigDecimal $total, string $amount): BigDecimal => $total->plus($amount), BigDecimal::zero()));
    }

    private function money(BigDecimal $amount): BigDecimal
    {
        return $amount->toScale(2, RoundingMode::HalfUp);
    }
}
