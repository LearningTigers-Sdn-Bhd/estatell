<?php

namespace App\Services\Reservations;

use App\Enums\ChargingUnit;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Services\Boats\BoatFareAddOns;
use App\Services\Catalogues\ChargeChildPrices;
use App\Services\Catalogues\ChargeQuantityResolver;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * What Change guests does to the per-guest add-ons that are already charged (a jetty fee charged at check-in). For
 * each item and service date it compares the charged quantity of each age group with the quantity for the new party:
 *
 * - More guests only: charge the difference ("Jetty Fee for 1 more adult").
 * - Fewer guests in any group: reverse the charged lines and charge the new party again.
 *
 * The price of each row comes from the charged row of the same age group, so a price the hotel typed is kept. A new
 * age group takes its child price of the charged adult amount. Boat fares are left out (`ResyncBoatFares`), and so are
 * per-guest-per-night items, whose charged nights are past. It writes nothing. See
 * docs/12-reservations/14-change-guests.md#charged-add-ons.
 */
class PostedAddOnAdjustments
{
    public function __construct(
        private ChargeChildPrices $childPrices,
        private ChargeQuantityResolver $quantityResolver,
        private BoatFareAddOns $boatFareAddOns,
    ) {}

    /**
     * @param  list<int>  $childAges
     * @return list<PostedAddOnAdjustment>
     */
    public function plan(ReservationUnit $unit, int $adults, array $childAges, CarbonImmutable $businessDate): array
    {
        $fareIds = $this->boatFareAddOns->idsFor($unit);
        $posted = $unit->addOns()
            ->whereNotNull('folio_line_id')
            ->whereNotIn('id', $fareIds)
            ->whereDoesntHave('folioLine.reversal')
            ->with(['chargeItem', 'folioLine'])
            ->orderBy('id')
            ->get()
            ->filter(fn (UnitAddOn $addOn): bool => $addOn->chargeItem->charging_unit === ChargingUnit::PerGuest && $addOn->folioLine !== null);

        $party = $unit->replicate();
        $party->adults = $adults;
        $party->children = count($childAges);
        $currency = $unit->property->settings?->currency->getCurrencyCode() ?? 'MYR';
        $adjustments = [];

        foreach ($posted->groupBy(fn (UnitAddOn $addOn): string => $addOn->charge_item_id.'|'.$addOn->service_date?->toDateString()) as $key => $rows) {
            $item = $rows->first()->chargeItem;
            $adultRow = $rows->first(fn (UnitAddOn $addOn): bool => $addOn->property_age_group_id === null);
            $default = $item->default_amount === null ? null : Money::of($item->default_amount, $currency);
            $adultAmount = $adultRow !== null ? Money::of($adultRow->unit_amount, $currency) : ($default ?? Money::zero($currency));
            $split = $this->childPrices->split($item, $childAges, count($childAges));
            $target = [];

            foreach ($this->quantityResolver->rows(ChargingUnit::PerGuest, $party, $businessDate, null, $businessDate, $split) as $row) {
                $group = $row['age_group_id'] ?? 0;
                $charged = $rows->first(fn (UnitAddOn $addOn): bool => (int) $addOn->property_age_group_id === $group);
                $target[$group] = [
                    'quantity' => $row['quantity'],
                    'age_group_id' => $row['age_group_id'],
                    'service_date' => $businessDate->toDateString(),
                    'unit_amount' => $charged !== null ? (string) BigDecimal::of($charged->unit_amount)->toScale(2) : (string) $this->childPrices->rowAmount($row['child_price'], $adultAmount, $default)->getAmount(),
                ];
            }

            $chargedQuantity = [];

            foreach ($rows as $row) {
                $group = (int) $row->property_age_group_id;
                $chargedQuantity[$group] = ($chargedQuantity[$group] ?? 0) + $row->quantity;
            }

            $fewer = array_any(array_keys($chargedQuantity), fn (int $group): bool => ($target[$group]['quantity'] ?? 0) < $chargedQuantity[$group]);
            $newRows = [];

            foreach ($target as $group => $row) {
                $quantity = $fewer ? $row['quantity'] : $row['quantity'] - ($chargedQuantity[$group] ?? 0);

                if ($quantity > 0) {
                    $newRows[] = [...$row, 'quantity' => $quantity];
                }
            }

            if (! $fewer && $newRows === []) {
                continue;
            }

            $adjustments[] = new PostedAddOnAdjustment(
                key: $key,
                item: $item,
                reverseLines: $fewer ? array_values($rows->map(fn (UnitAddOn $addOn): FolioLine => $addOn->folioLine)->all()) : [],
                newRows: $newRows,
                reverseAmount: $fewer ? $this->total($rows->map(fn (UnitAddOn $addOn): array => ['quantity' => $addOn->quantity, 'unit_amount' => $addOn->unit_amount])->all()) : '0.00',
                chargeAmount: $this->total($newRows),
                summary: $this->summary($fewer, $newRows, $chargedQuantity, $target),
            );
        }

        return $adjustments;
    }

    /**
     * @param  iterable<array{quantity: int, unit_amount: string}>  $rows
     */
    private function total(iterable $rows): string
    {
        $sum = BigDecimal::zero();

        foreach ($rows as $row) {
            $sum = $sum->plus(BigDecimal::of($row['unit_amount'])->multipliedBy($row['quantity']));
        }

        return (string) $sum->toScale(2);
    }

    /**
     * "Charge 1 more guest" or "Reverse 3 guests and charge 2".
     *
     * @param  list<array{quantity: int, age_group_id: int|null, service_date: string, unit_amount: string}>  $newRows
     * @param  array<int, int>  $chargedQuantity
     * @param  array<int, array{quantity: int, age_group_id: int|null, service_date: string, unit_amount: string}>  $target
     */
    private function summary(bool $fewer, array $newRows, array $chargedQuantity, array $target): string
    {
        $was = array_sum($chargedQuantity);
        $now = array_sum(array_column($target, 'quantity'));

        if (! $fewer) {
            return trans_choice('Charge :count more guest|Charge :count more guests', array_sum(array_column($newRows, 'quantity')));
        }

        return $now === 0
            ? trans_choice('Reverse :count guest|Reverse :count guests', $was)
            : __('Reverse :was and charge :now', ['was' => trans_choice(':count guest|:count guests', $was), 'now' => trans_choice(':count guest|:count guests', $now)]);
    }
}
