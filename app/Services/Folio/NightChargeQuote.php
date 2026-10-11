<?php

namespace App\Services\Folio;

use App\Enums\DiscountType;
use App\Models\Discount;
use App\Models\Property;
use App\Models\RatePlanInclusion;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Services\Catalogues\TaxCalculationResult;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Packages\InclusionAllocator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * Works out the lines of one unit night, for `PostRoomCharges` to post and for the folio page to show as Scheduled
 * rows, so the two never differ. It reads and writes nothing. The gross is the night's parts, or the override.
 * `InclusionAllocator` splits it into the room and the inclusions of the night's rate plan. The stay discount and the
 * booking-time discount apply to the room only, and an overridden night has none. Tax is calculated on each charge
 * after its discounts. The booker's nationality drives foreign-guest taxes, as `PostCharge` does. The night needs
 * `ratePlan.inclusions.chargeItem.transactionCode` loaded and the unit needs `discount`. See
 * docs/13-folio/02-posting-charges.md.
 */
class NightChargeQuote
{
    public function __construct(
        private TaxCalculator $taxCalculator,
        private InclusionAllocator $inclusionAllocator,
    ) {}

    /**
     * The property's taxes with the ids of their codes, read once for all the nights of a run.
     *
     * @return Collection<int, Tax>
     */
    public function propertyTaxes(Property $property): Collection
    {
        return $property->taxes()->with('applicableTransactionCodes:id')->get();
    }

    /**
     * `$key` is the key of the room line, and the other keys follow it. It is `night:{id}`, or `night:{id}:fix:{n}` for
     * a night corrected with Change price.
     *
     * @param  Collection<int, Tax>  $propertyTaxes  From `propertyTaxes()`.
     */
    public function quote(Property $property, Collection $propertyTaxes, ReservationUnit $unit, UnitNight $night, bool $firstNight, TransactionCode $roomCode, ?string $nationality, ?string $key = null): NightQuote
    {
        $currency = $property->settings->currency->value;
        $overridden = $night->override_amount !== null;
        $gross = $overridden ? Money::of($night->override_amount, $currency) : Money::of($night->base_amount, $currency)->plus($night->extra_amount)->plus($night->supplement_amount);
        $room = $gross;
        $inclusionLines = [];
        $inclusions = $night->ratePlan->inclusions->keyBy('id');

        if ($inclusions->isNotEmpty()) {
            $split = $this->inclusionAllocator->allocate($gross, $inclusions, $unit->adults + $unit->children, $firstNight);

            if ($split->isRefused()) {
                return NightQuote::refused((string) $split->refusal);
            }

            $room = $split->room;
            $inclusionLines = $split->lines;
        }

        $stayDiscount = $overridden ? Money::zero($currency) : Money::of($night->discount_amount, $currency);
        $net = $room->minus($stayDiscount);

        if ($net->isNegative()) {
            return NightQuote::refused(NightQuote::REFUSAL_DISCOUNT_EXCEEDS_ROOM);
        }

        $bookingDiscount = ($overridden || $unit->discount === null) ? Money::zero($currency) : $this->bookingDiscount($unit->discount, $net, $firstNight, $currency);
        $key ??= 'night:'.$night->id;
        $discounts = [];

        if ($stayDiscount->isPositive()) {
            $discounts[] = new QuotedDiscount($key.':stay-discount', 'Stay discount', $stayDiscount, null);
        }

        if ($bookingDiscount->isPositive()) {
            $discounts[] = new QuotedDiscount($key.':discount', $unit->discount->name, $bookingDiscount, $unit->discount->transaction_code_id);
        }

        $entered = $net->minus($bookingDiscount);
        $tax = $this->tax($property, $propertyTaxes, $roomCode, $entered, $nationality);
        $shift = $tax->isCalculated() ? $entered->minus($tax->netAmount) : Money::zero($currency);
        $charges = [new QuotedCharge($key, $roomCode, 'Room · '.$night->ratePlan->name, $room, $room->minus($shift), $discounts, $tax)];

        foreach ($inclusionLines as $line) {
            /** @var RatePlanInclusion $inclusion */
            $inclusion = $inclusions->get($line->inclusionId);
            $code = $inclusion->chargeItem->transactionCode;
            $lineTax = $this->tax($property, $propertyTaxes, $code, $line->amount, $nationality);
            $charges[] = new QuotedCharge($key.':incl:'.$inclusion->id, $code, $inclusion->chargeItem->name, $line->amount, $lineTax->isCalculated() ? $lineTax->netAmount : $line->amount, [], $lineTax);
        }

        return NightQuote::calculated($charges);
    }

    /**
     * A Percent discount takes its share of every night. A Fixed discount is taken once per stay, from the first
     * night, and never more than that night. An Open discount has no amount at booking time.
     */
    private function bookingDiscount(Discount $discount, Money $net, bool $firstNight, string $currency): Money
    {
        $zero = Money::zero($currency);

        return match ($discount->type) {
            DiscountType::Percent => $net->multipliedBy(BigDecimal::of((string) $discount->value)->dividedBy(100, 8), RoundingMode::HalfUp),
            DiscountType::Fixed => $firstNight ? Money::min(Money::of((string) $discount->value, $currency), $net) : $zero,
            DiscountType::Open => $zero,
        };
    }

    /**
     * @param  Collection<int, Tax>  $propertyTaxes
     */
    private function tax(Property $property, Collection $propertyTaxes, TransactionCode $code, Money $entered, ?string $nationality): TaxCalculationResult
    {
        $taxes = $propertyTaxes->filter(fn (Tax $tax): bool => $tax->applicableTransactionCodes->contains('id', $code->id));

        return $this->taxCalculator->calculateWithTaxes($property, $taxes, $entered, '1', $nationality);
    }
}
