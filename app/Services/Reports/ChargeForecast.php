<?php

namespace App\Services\Reports;

use App\Enums\StayType;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Services\Catalogues\CalculatedTax;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Folio\DayUseChargeQuote;
use App\Services\Folio\NightChargeQuote;
use App\Services\Folio\NightQuote;
use App\Services\Reservations\UnitNationality;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The charges the bookings will post on each date from one date to another, before they are posted: the unposted
 * nights, day use and scheduled add-ons of the units that hold inventory. Each night is quoted by `NightChargeQuote`
 * and each day use by `DayUseChargeQuote`, the same classes the night audit posts with and the folio shows as
 * Scheduled rows, so a forecast never differs from what will post at today's prices and taxes. It reads only.
 *
 * - **Room** is the room line of each night after its discounts (net of inclusive tax), like the room revenue.
 * - **Extra** is every other charge: the inclusions of the nights, day use and scheduled add-ons.
 * - **Tax** is the tax of all of them. A charge whose tax cannot be worked out (a missing nationality) adds no tax.
 *
 * See docs/22-reports/04-history-and-forecast.md.
 */
class ChargeForecast
{
    /** @var Collection<int, Tax>|null */
    private ?Collection $propertyTaxes = null;

    public function __construct(
        private NightChargeQuote $nightChargeQuote,
        private DayUseChargeQuote $dayUseChargeQuote,
        private TaxCalculator $taxCalculator,
        private UnitNationality $unitNationality,
    ) {}

    /**
     * The forecast of each date that has any, keyed by `Y-m-d`. Read in a fixed number of queries.
     *
     * @return array<string, array{room: BigDecimal, extra: BigDecimal, tax: BigDecimal}>
     */
    public function between(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $codes = TransactionCode::query()
            ->where('property_id', $property->id)
            ->whereIn('system_key', [SystemTransactionCode::RoomRevenue->value, SystemTransactionCode::DayUseRevenue->value])
            ->get()
            ->keyBy('system_key');
        $roomCode = $codes->get(SystemTransactionCode::RoomRevenue->value);
        $dayUseCode = $codes->get(SystemTransactionCode::DayUseRevenue->value);
        $currency = $property->loadMissing('settings')->settings->currency->getCurrencyCode();
        $this->propertyTaxes = $this->nightChargeQuote->propertyTaxes($property);
        $units = $this->units($property, $from, $to);
        $posted = $this->postedKeys($units);
        $nationalities = $this->unitNationality->forUnits($units);
        $totals = [];

        foreach ($units as $unit) {
            $nationality = $nationalities[$unit->id] ?? null;

            foreach ($roomCode === null ? [] : $unit->nights as $index => $night) {
                $date = $night->date->toDateString();

                if ($date < $from->toDateString() || $date > $to->toDateString() || $posted->has('night:'.$night->id)) {
                    continue;
                }

                $this->addQuote($totals, $date, $this->nightChargeQuote->quote($property, $this->propertyTaxes, $unit, $night, $index === 0, $roomCode, $nationality), $roomCode);
            }

            $dayUse = $unit->stay_type === StayType::DayUse ? $unit->dayUse : null;

            if ($dayUse !== null && $dayUseCode !== null && $dayUse->date->betweenIncluded($from, $to) && ! $posted->has('dayuse:'.$unit->id)) {
                $this->addQuote($totals, $dayUse->date->toDateString(), $this->dayUseChargeQuote->quote($property, $this->propertyTaxes, $unit, $dayUse, $dayUseCode, $nationality), $roomCode);
            }

            foreach ($unit->addOns as $addOn) {
                $date = ($addOn->service_date ?? $unit->arrival_date)->toDateString();

                if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                    $this->addAddOn($totals, $date, $property, $addOn, $currency, $nationality);
                }
            }
        }

        return $totals;
    }

    /**
     * The units that hold inventory and touch the dates, with what the quotes need.
     *
     * @return EloquentCollection<int, ReservationUnit>
     */
    private function units(Property $property, CarbonImmutable $from, CarbonImmutable $to): EloquentCollection
    {
        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->where('arrival_date', '<=', $to->toDateString())
            ->where('departure_date', '>=', $from->toDateString())
            ->with([
                'reservation', 'discount', 'taxWaivers', 'dayUse', 'ratePlan.inclusions.chargeItem.transactionCode',
                'nights' => fn ($nights) => $nights->orderBy('date'),
                'nights.ratePlan.inclusions.chargeItem.transactionCode',
                'addOns' => fn ($addOns) => $addOns->whereNull('folio_line_id'),
                'addOns.chargeItem.transactionCode',
            ])
            ->get();
    }

    /**
     * The idempotency keys of the nights and day uses already on a folio, so they are not counted twice.
     *
     * @param  Collection<int, ReservationUnit>  $units
     * @return Collection<string, int>
     */
    private function postedKeys(Collection $units): Collection
    {
        $keys = [
            ...$units->flatMap(fn (ReservationUnit $unit): Collection => $unit->nights->map(fn (UnitNight $night): string => 'night:'.$night->id))->all(),
            ...$units->filter(fn (ReservationUnit $unit): bool => $unit->stay_type === StayType::DayUse)->map(fn (ReservationUnit $unit): string => 'dayuse:'.$unit->id)->all(),
        ];

        return $keys === [] ? collect() : FolioLine::query()->whereIn('idempotency_key', $keys)->pluck('idempotency_key')->flip();
    }

    /**
     * @param  array<string, array{room: BigDecimal, extra: BigDecimal, tax: BigDecimal}>  $totals
     */
    private function addQuote(array &$totals, string $date, NightQuote $quote, ?TransactionCode $roomCode): void
    {
        foreach ($quote->isRefused() ? [] : $quote->charges as $charge) {
            $net = array_reduce($charge->discounts, fn (Money $amount, $discount): Money => $amount->minus($discount->amount), $charge->lineAmount);
            $bucket = $roomCode !== null && $charge->code->id === $roomCode->id ? 'room' : 'extra';

            $this->add($totals, $date, $bucket, $net->getAmount());
            $this->add($totals, $date, 'tax', $this->taxOf($charge->tax->isCalculated() ? $charge->tax->taxes : []));
        }
    }

    /**
     * @param  array<string, array{room: BigDecimal, extra: BigDecimal, tax: BigDecimal}>  $totals
     */
    private function addAddOn(array &$totals, string $date, Property $property, UnitAddOn $addOn, string $currency, ?string $nationality): void
    {
        $code = $addOn->chargeItem->transactionCode;
        $entered = Money::of($addOn->unit_amount, $currency)->multipliedBy($addOn->quantity);
        $taxes = ($this->propertyTaxes ?? collect())->filter(fn (Tax $tax): bool => $tax->applicableTransactionCodes->contains('id', $code->id));
        $tax = $this->taxCalculator->calculateWithTaxes($property, $taxes, $entered, (string) $addOn->quantity, $nationality);

        $this->add($totals, $date, 'extra', ($tax->isCalculated() ? $tax->netAmount : $entered)?->getAmount() ?? BigDecimal::zero());
        $this->add($totals, $date, 'tax', $this->taxOf($tax->isCalculated() ? $tax->taxes : []));
    }

    /**
     * @param  list<CalculatedTax>  $taxes
     */
    private function taxOf(array $taxes): BigDecimal
    {
        return array_reduce($taxes, fn (BigDecimal $sum, $tax): BigDecimal => $sum->plus($tax->amount->getAmount()), BigDecimal::zero());
    }

    /**
     * @param  array<string, array{room: BigDecimal, extra: BigDecimal, tax: BigDecimal}>  $totals
     * @param  'room'|'extra'|'tax'  $bucket
     */
    private function add(array &$totals, string $date, string $bucket, BigDecimal $amount): void
    {
        $totals[$date] ??= ['room' => BigDecimal::zero(), 'extra' => BigDecimal::zero(), 'tax' => BigDecimal::zero()];
        $totals[$date][$bucket] = $totals[$date][$bucket]->plus($amount);
    }
}
