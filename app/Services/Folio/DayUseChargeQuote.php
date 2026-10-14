<?php

namespace App\Services\Folio;

use App\Models\Property;
use App\Models\RatePlanInclusion;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitDayUse;
use App\Services\Catalogues\TaxCalculationResult;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Packages\InclusionAllocator;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * Works out the lines of one day use block, for the folio page to show as a Scheduled row now and for check in to post
 * later, so the two never differ. It reads and writes nothing. The block price is split by `InclusionAllocator` into
 * the room and the plan's inclusions, which are allocated as for one night (every inclusion once). The tax comes from
 * the taxes of the DAY_USE code, which never follows the Taxes tab. There is no discount and no override. The booker's
 * nationality drives foreign-guest taxes, as `PostCharge` does. The unit needs `ratePlan.inclusions.chargeItem.transactionCode`
 * loaded. Keys: `dayuse:{unit_id}` and `dayuse:{unit_id}:incl:{inclusion_id}`. See docs/12-reservations/10-day-use.md.
 */
class DayUseChargeQuote
{
    public function __construct(
        private TaxCalculator $taxCalculator,
        private InclusionAllocator $inclusionAllocator,
    ) {}

    /**
     * @param  Collection<int, Tax>  $propertyTaxes  From `NightChargeQuote::propertyTaxes()`.
     */
    public function quote(Property $property, Collection $propertyTaxes, ReservationUnit $unit, UnitDayUse $dayUse, TransactionCode $dayUseCode, ?string $nationality): NightQuote
    {
        $currency = $property->settings->currency->getCurrencyCode();
        $gross = Money::of($dayUse->amount, $currency);
        $room = $gross;
        $inclusionLines = [];
        $inclusions = $unit->ratePlan->inclusions->keyBy('id');

        if ($inclusions->isNotEmpty()) {
            $split = $this->inclusionAllocator->allocate($gross, $inclusions, $unit->adults + $unit->children, true);

            if ($split->isRefused()) {
                return NightQuote::refused((string) $split->refusal);
            }

            $room = $split->room;
            $inclusionLines = $split->lines;
        }

        $key = 'dayuse:'.$unit->id;
        $description = __('Day use').' · '.substr($dayUse->starts_at, 0, 5).'–'.substr($dayUse->ends_at, 0, 5);
        $tax = $this->tax($property, $propertyTaxes, $dayUseCode, $room, $nationality);
        $shift = $tax->isCalculated() ? $room->minus($tax->netAmount) : Money::zero($currency);
        $charges = [new QuotedCharge($key, $dayUseCode, $description, $room, $room->minus($shift), [], $tax)];

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
     * @param  Collection<int, Tax>  $propertyTaxes
     */
    private function tax(Property $property, Collection $propertyTaxes, TransactionCode $code, Money $entered, ?string $nationality): TaxCalculationResult
    {
        $taxes = $propertyTaxes->filter(fn (Tax $tax): bool => $tax->applicableTransactionCodes->contains('id', $code->id));

        return $this->taxCalculator->calculateWithTaxes($property, $taxes, $entered, '1', $nationality);
    }
}
