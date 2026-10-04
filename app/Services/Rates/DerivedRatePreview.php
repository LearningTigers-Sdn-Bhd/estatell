<?php

namespace App\Services\Rates;

use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Models\PackagePrice;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Rates\Pricing\NightPrice;
use Brick\Money\Money;
use Carbon\CarbonInterface;

/**
 * Shows what a derivation would do to the parent's room price on one date, before it is saved. For a package it is the
 * package price of the period that holds the date. It reads only.
 */
class DerivedRatePreview
{
    public function __construct(private DerivedRateResolver $resolver) {}

    /**
     * The parent's room price and the derived price on the date, for each active room type the plan sells.
     * A price is null when the parent has no rate for that room type on the date.
     *
     * @return list<array{room_type_id: int, name: string, parent_amount: string|null, derived_amount: string|null}>
     */
    public function preview(RatePlan $plan, RatePlan $parent, RateDerivation $derivation, string $value, CarbonInterface $date, string $currency): array
    {
        $derived = new RatePlan(['derivation' => $derivation, 'derivation_value' => $value]);
        $roomTypes = $plan->roomTypes()->active()->ordered()->get();
        $rates = $parent->pricing_basis === PricingBasis::Package
            ? PackagePrice::query()
                ->where('rate_plan_id', $parent->id)
                ->whereIn('room_type_id', $roomTypes->pluck('id'))
                ->whereDate('arrival_from', '<=', $date->toDateString())
                ->whereDate('arrival_to', '>=', $date->toDateString())
                ->pluck('amount', 'room_type_id')
            : Rate::query()
                ->where('rate_plan_id', $parent->id)
                ->whereIn('room_type_id', $roomTypes->pluck('id'))
                ->whereDate('date', $date->toDateString())
                ->whereNotNull('amount')
                ->pluck('amount', 'room_type_id');

        return $roomTypes->map(function (RoomType $roomType) use ($rates, $derived, $date, $currency): array {
            $amount = $rates->get($roomType->id);

            if ($amount === null) {
                return ['room_type_id' => $roomType->id, 'name' => $roomType->name, 'parent_amount' => null, 'derived_amount' => null];
            }

            $zero = Money::zero($currency);
            $night = NightPrice::of($date, Money::of($amount, $currency), $zero, $zero);

            return [
                'room_type_id' => $roomType->id,
                'name' => $roomType->name,
                'parent_amount' => (string) $night->total()->getAmount(),
                'derived_amount' => (string) $this->resolver->resolve($night, $derived, 1)->total()->getAmount(),
            ];
        })->all();
    }
}
