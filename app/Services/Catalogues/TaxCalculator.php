<?php

namespace App\Services\Catalogues;

use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
use App\Models\Property;
use App\Models\Tax;
use App\Models\TransactionCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class TaxCalculator
{
    public const REFUSAL_NATIONALITY_REQUIRED = 'nationality_required';

    public const REFUSAL_INCLUSIVE_TAX_EXCEEDS_AMOUNT = 'inclusive_tax_exceeds_amount';

    public function calculate(
        Property $property,
        TransactionCode $sourceCode,
        Money $enteredAmount,
        string $quantity,
        ?string $guestNationalityCode,
    ): TaxCalculationResult {
        if ($sourceCode->property_id !== $property->id || $sourceCode->kind !== TransactionKind::Charge) {
            throw new InvalidArgumentException('The source transaction code must be a charge code from the property.');
        }

        $taxes = $property->taxes()
            ->whereHas('applicableTransactionCodes', fn ($query) => $query->whereKey($sourceCode->id))
            ->get();

        return $this->calculateWithTaxes($property, $taxes, $enteredAmount, $quantity, $guestNationalityCode);
    }

    /**
     * Calculate a given set of the property's taxes, for example to preview a selection before it is saved.
     * Disabled taxes are skipped.
     *
     * @param  Collection<int, Tax>  $taxes
     */
    public function calculateWithTaxes(
        Property $property,
        Collection $taxes,
        Money $enteredAmount,
        string $quantity,
        ?string $guestNationalityCode,
    ): TaxCalculationResult {
        $this->validateContext($property, $taxes, $enteredAmount, $quantity);
        $quantityDecimal = BigDecimal::of($quantity);
        $nationalityCode = filled($guestNationalityCode) ? mb_strtoupper(trim((string) $guestNationalityCode)) : null;
        $taxes = $taxes
            ->filter(fn (Tax $tax): bool => $tax->enabled)
            ->reject(fn (Tax $tax): bool => $tax->foreign_guests_only && $nationalityCode === 'MY')
            ->sortBy('id')
            ->values();

        if ($nationalityCode === null && $taxes->contains(fn (Tax $tax): bool => $tax->foreign_guests_only)) {
            return TaxCalculationResult::refused(self::REFUSAL_NATIONALITY_REQUIRED);
        }

        $currency = $enteredAmount->getCurrency();
        $amounts = [];
        $inclusiveFlat = $taxes->filter(fn (Tax $tax): bool => $tax->is_inclusive && $tax->rate_type === TaxRateType::Flat);
        $inclusivePercent = $taxes->filter(fn (Tax $tax): bool => $tax->is_inclusive && $tax->rate_type === TaxRateType::Percent);
        $inclusiveFlatTotal = Money::zero($currency);

        foreach ($inclusiveFlat as $tax) {
            $amount = Money::of($tax->rate, $currency)->multipliedBy($quantityDecimal, RoundingMode::HalfUp);
            $amounts[$tax->id] = $amount;
            $inclusiveFlatTotal = $inclusiveFlatTotal->plus($amount);
        }

        if ($inclusiveFlatTotal->isGreaterThan($enteredAmount)) {
            return TaxCalculationResult::refused(self::REFUSAL_INCLUSIVE_TAX_EXCEEDS_AMOUNT);
        }

        $remainingInclusiveGross = $enteredAmount->minus($inclusiveFlatTotal);
        $netAmount = $this->extractInclusivePercentages($remainingInclusiveGross, $inclusivePercent, $amounts);
        $exclusiveTotal = Money::zero($currency);

        foreach ($taxes->where('is_inclusive', false) as $tax) {
            $amount = $tax->rate_type === TaxRateType::Percent
                ? $netAmount->multipliedBy(BigDecimal::of($tax->rate)->dividedBy(100, 8), RoundingMode::HalfUp)
                : Money::of($tax->rate, $currency)->multipliedBy($quantityDecimal, RoundingMode::HalfUp);
            $amounts[$tax->id] = $amount;
            $exclusiveTotal = $exclusiveTotal->plus($amount);
        }

        $components = array_values($taxes->map(fn (Tax $tax): CalculatedTax => new CalculatedTax(
            taxId: $tax->id,
            transactionCodeId: $tax->transaction_code_id,
            name: $tax->name,
            amount: $amounts[$tax->id],
            inclusive: $tax->is_inclusive,
        ))->all());

        return TaxCalculationResult::calculated($netAmount, $components, $enteredAmount->plus($exclusiveTotal));
    }

    /**
     * @param  Collection<int, Tax>  $taxes
     * @param  array<int, Money>  $amounts
     */
    private function extractInclusivePercentages(Money $gross, Collection $taxes, array &$amounts): Money
    {
        if ($taxes->isEmpty()) {
            return $gross;
        }

        $combinedRate = $taxes->reduce(
            fn (BigDecimal $sum, Tax $tax): BigDecimal => $sum->plus(BigDecimal::of($tax->rate)->dividedBy(100, 8)),
            BigDecimal::zero(),
        );
        $net = $gross->dividedBy(BigDecimal::one()->plus($combinedRate), RoundingMode::HalfUp);
        $taxTotal = Money::zero($gross->getCurrency());

        foreach ($taxes as $tax) {
            $amount = $net->multipliedBy(BigDecimal::of($tax->rate)->dividedBy(100, 8), RoundingMode::HalfUp);
            $amounts[$tax->id] = $amount;
            $taxTotal = $taxTotal->plus($amount);
        }

        $remainder = $gross->minus($net)->minus($taxTotal);
        $lastTax = $taxes->last();
        $amounts[$lastTax->id] = $amounts[$lastTax->id]->plus($remainder);

        return $net;
    }

    /** @param Collection<int, Tax> $taxes */
    private function validateContext(Property $property, Collection $taxes, Money $amount, string $quantity): void
    {
        if ($taxes->contains(fn (Tax $tax): bool => $tax->property_id !== $property->id)) {
            throw new InvalidArgumentException('Every tax must belong to the property.');
        }

        if ($property->settings === null || $amount->getCurrency()->getCurrencyCode() !== $property->settings->currency->value) {
            throw new InvalidArgumentException('The entered amount must use the property currency.');
        }

        if (BigDecimal::of($quantity)->isNegativeOrZero()) {
            throw new InvalidArgumentException('The tax quantity must be positive.');
        }
    }
}
