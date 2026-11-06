<?php

namespace App\Services\BookingEngine;

use App\Enums\SystemTransactionCode;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Services\Catalogues\CalculatedTax;
use App\Services\Catalogues\ChargeChildPrices;
use App\Services\Catalogues\ChargeQuantityResolver;
use App\Services\Catalogues\CheckInTaxRule;
use App\Services\Catalogues\TaxCalculationResult;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Folio\NightChargeQuote;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use App\Services\Rates\Pricing\NightPrice;
use App\Services\Rates\RateQuote;
use App\Services\Reservations\AutoAddOns;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The price of one room or bed for a stay, split into what the guest pays online and at the property. It quotes the
 * nights with `NightChargeQuote` and the auto-added items with `TaxCalculator`, as the posting does, on a unit that is
 * not saved, so the offer shows what the folio will hold. It reads only.
 * See docs/29-booking-engine/03-checkout-and-hold.md#taxes-in-the-online-total.
 */
class OnlineStayPrice
{
    public const string REFUSAL_NOT_PRICED = 'pricing_unavailable';

    public function __construct(
        private NightChargeQuote $nightChargeQuote,
        private TaxCalculator $taxCalculator,
        private CheckInTaxRule $checkInTaxRule,
        private AutoAddOns $autoAddOns,
        private ChargeQuantityResolver $quantityResolver,
        private ChargeChildPrices $childPrices,
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
    ) {}

    public function context(Property $property): OnlinePriceContext
    {
        $property->loadMissing('settings');

        return new OnlinePriceContext(
            $property,
            TransactionCode::query()->where('property_id', $property->id)->where('system_key', SystemTransactionCode::RoomRevenue->value)->first(),
            $this->nightChargeQuote->propertyTaxes($property),
            array_values(array_map(intval(...), $this->checkInTaxRule->flaggedTaxes($property)->modelKeys())),
            $this->autoAddOns->items($property),
            $this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property),
        );
    }

    /**
     * @param  RatePlan  $plan  With `inclusions.chargeItem.transactionCode` loaded.
     * @param  RateQuote  $quote  A quote that was not refused.
     * @param  list<int>  $childAges
     */
    public function price(OnlinePriceContext $context, RatePlan $plan, RateQuote $quote, CarbonImmutable $arrival, CarbonImmutable $departure, int $adults, array $childAges, ?string $nationality): OnlinePrice
    {
        if ($context->roomCode === null) {
            return OnlinePrice::refused(self::REFUSAL_NOT_PRICED);
        }

        $unit = (new ReservationUnit)->forceFill(['arrival_date' => $arrival, 'departure_date' => $departure, 'adults' => $adults, 'children' => count($childAges)]);
        $unit->setRelation('discount', null);
        /** @var list<array{key: string, tax: TaxCalculationResult}> $charges */
        $charges = [];

        foreach ($quote->nights as $index => $price) {
            $nightQuote = $this->nightChargeQuote->quote($context->property, $context->taxes, $unit, $this->night($plan, $price), $index === 0, $context->roomCode, $nationality, 'night:'.$index);

            if ($nightQuote->isRefused()) {
                return OnlinePrice::refused((string) $nightQuote->refusal);
            }

            foreach ($nightQuote->charges as $charge) {
                $charges[] = ['key' => $charge->key, 'tax' => $charge->tax];
            }
        }

        foreach ($context->autoAddOns as $item) {
            array_push($charges, ...$this->addOnCharges($context, $item, $unit, $childAges, $nationality));
        }

        return $this->split($context, $charges);
    }

    private function night(RatePlan $plan, NightPrice $price): UnitNight
    {
        $night = (new UnitNight)->forceFill([
            'date' => $price->date,
            'base_amount' => (string) $price->base->getAmount(),
            'extra_amount' => (string) $price->extra->getAmount(),
            'supplement_amount' => (string) $price->supplement->getAmount(),
            'discount_amount' => (string) $price->discountAmount->getAmount(),
            'override_amount' => null,
        ]);

        return $night->setRelation('ratePlan', $plan);
    }

    /**
     * One charge for each row the add-on will post. An item with no default amount cannot be priced, so it is left out,
     * as in `BookingQuote`.
     *
     * @param  list<int>  $childAges
     * @return list<array{key: string, tax: TaxCalculationResult}>
     */
    private function addOnCharges(OnlinePriceContext $context, ChargeItem $item, ReservationUnit $unit, array $childAges, ?string $nationality): array
    {
        if ($item->default_amount === null) {
            return [];
        }

        $currency = $context->property->settings->currency->getCurrencyCode();
        $price = Money::of($item->default_amount, $currency, roundingMode: RoundingMode::Down);
        $split = $this->childPrices->split($item, $childAges, count($childAges));
        $taxes = $context->taxes->filter(fn (Tax $tax): bool => $tax->applicableTransactionCodes->contains('id', $item->transaction_code_id));
        $charges = [];

        foreach ($this->quantityResolver->rows($item->charging_unit, $unit, $context->businessDate, null, null, $split) as $index => $row) {
            $entered = $this->childPrices->rowAmount($row['child_price'], $price, $price)->multipliedBy($row['quantity']);

            $charges[] = [
                'key' => 'addon:'.$item->id.':'.$index,
                'tax' => $this->taxCalculator->calculateWithTaxes($context->property, $taxes, $entered, (string) $row['quantity'], $nationality),
            ];
        }

        return $charges;
    }

    /**
     * The online total is every charge with its tax, less the taxes paid at the desk. That also holds for an inclusive
     * flagged tax: it comes out of the price, so it is not paid twice.
     *
     * @param  list<array{key: string, tax: TaxCalculationResult}>  $charges
     */
    private function split(OnlinePriceContext $context, array $charges): OnlinePrice
    {
        $zero = Money::zero($context->property->settings->currency->getCurrencyCode());
        $gross = $zero;
        $deskTotal = $zero;
        $exclusiveOnline = $zero;
        /** @var array<int, OnlineTaxLine> $online */
        $online = [];
        /** @var array<int, OnlineTaxLine> $desk */
        $desk = [];

        foreach ($charges as $charge) {
            if (! $charge['tax']->isCalculated()) {
                return OnlinePrice::refused((string) $charge['tax']->refusalReason);
            }

            $gross = $gross->plus($charge['tax']->grossAmount);

            foreach ($charge['tax']->taxes as $tax) {
                if ($this->checkInTaxRule->deskTaxId($charge['key'].':tax:'.$tax->taxId, $context->flaggedTaxIds) !== null) {
                    $deskTotal = $deskTotal->plus($tax->amount);
                    $desk[$tax->taxId] = $this->added($desk[$tax->taxId] ?? null, $tax);

                    continue;
                }

                $online[$tax->taxId] = $this->added($online[$tax->taxId] ?? null, $tax);

                if (! $tax->inclusive) {
                    $exclusiveOnline = $exclusiveOnline->plus($tax->amount);
                }
            }
        }

        ksort($online);
        ksort($desk);
        $onlineTotal = $gross->minus($deskTotal);

        return OnlinePrice::priced($onlineTotal->minus($exclusiveOnline), array_values($online), $onlineTotal, array_values($desk), $deskTotal);
    }

    private function added(?OnlineTaxLine $line, CalculatedTax $tax): OnlineTaxLine
    {
        return new OnlineTaxLine($tax->name, $line === null ? $tax->amount : $line->amount->plus($tax->amount), $tax->inclusive);
    }
}
