<?php

namespace App\Services\Catalogues;

use App\Enums\PolicyChargeMode;
use App\Enums\PolicyRateBasis;
use App\Enums\PolicyScope;
use App\Enums\StayType;
use App\Enums\SystemTransactionCode;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\UnitNight;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Works out what a no-show, cancellation, early departure or late check-out policy charges for a unit, before tax.
 * It reads only. The front desk Actions post the amount through `PostFolioCharge`. The policy applies as it is now,
 * because a booking keeps no copy of it. A day use unit has no nights: its block counts as one night priced at
 * `unit_day_uses.amount`, so First night, Whole stay and One charge all give the block price and Unused nights gives
 * nothing. Current rate prices the block again with `RateQuoteService::quoteDayUse()` and keeps the booked amount when
 * that is refused.
 */
class PolicyChargeCalculator
{
    public function __construct(
        private RateQuoteService $rateQuoteService,
        private CurrentBusinessDate $currentBusinessDate,
    ) {}

    /**
     * @param  CarbonInterface|null  $newDeparture  The new departure date, needed for an Unused nights policy. The unit still holds its booked nights.
     */
    public function calculate(RoomRevenuePolicy $policy, ReservationUnit $unit, ?CarbonInterface $newDeparture = null): PolicyCharge
    {
        $currency = $unit->property()->firstOrFail()->settings->currency->getCurrencyCode();
        $zero = Money::zero($currency);

        if ($this->isInsideFreeWindow($policy, $unit)) {
            return new PolicyCharge($zero, withinFreeWindow: true);
        }

        if ($policy->charge_mode === PolicyChargeMode::Free) {
            return new PolicyCharge($zero);
        }

        if ($unit->stay_type === StayType::DayUse) {
            return $this->dayUseCharge($policy, $unit, $zero);
        }

        $nights = $this->chargedNights($policy, $unit, $newDeparture);

        if ($nights->isEmpty() && $policy->applies_to === PolicyScope::UnusedNights) {
            return new PolicyCharge($zero);
        }

        return new PolicyCharge($this->amount($policy, $this->baseAmount($policy, $unit, $nights, $zero)));
    }

    private function dayUseCharge(RoomRevenuePolicy $policy, ReservationUnit $unit, Money $zero): PolicyCharge
    {
        $dayUse = $unit->dayUse()->first();

        if ($dayUse === null || $policy->applies_to === PolicyScope::UnusedNights) {
            return new PolicyCharge($zero);
        }

        $base = Money::of($dayUse->amount, $zero->getCurrency());

        if ($policy->percent_basis === PolicyRateBasis::CurrentRate) {
            $quote = $this->rateQuoteService->quoteDayUse($unit->roomType()->firstOrFail(), $unit->ratePlan()->firstOrFail(), $dayUse->date, $dayUse->hours);
            $base = $quote->isRefused() ? $base : $quote->amount;
        }

        return new PolicyCharge($this->amount($policy, $base));
    }

    /**
     * What the policy charges when the nights it covers add up to `$base`. A fixed amount ignores `$base`.
     */
    public function amount(RoomRevenuePolicy $policy, Money $base): Money
    {
        return match ($policy->charge_mode) {
            PolicyChargeMode::Free => Money::zero($base->getCurrency()),
            PolicyChargeMode::FixedAmount => Money::of($policy->amount, $base->getCurrency()),
            PolicyChargeMode::Percentage => $base->multipliedBy($policy->percent, RoundingMode::HalfUp)->dividedBy(100, RoundingMode::HalfUp),
        };
    }

    /**
     * A cancellation made this many days or more before arrival is free.
     */
    public function isFreeWhenCancelledDaysBefore(RoomRevenuePolicy $policy, int $daysBeforeArrival): bool
    {
        return $policy->code === SystemTransactionCode::CancellationRevenue
            && $policy->free_until_days !== null
            && $daysBeforeArrival >= $policy->free_until_days;
    }

    /**
     * A cancellation made this many days or more before arrival is free, counted from the business date.
     */
    private function isInsideFreeWindow(RoomRevenuePolicy $policy, ReservationUnit $unit): bool
    {
        if ($policy->code !== SystemTransactionCode::CancellationRevenue || $policy->free_until_days === null) {
            return false;
        }

        $businessDate = $this->currentBusinessDate->date($unit->property()->firstOrFail());

        if ($businessDate === null) {
            return false;
        }

        return $this->isFreeWhenCancelledDaysBefore($policy, (int) $businessDate->diffInDays($unit->arrival_date->toImmutable()->startOfDay(), false));
    }

    /**
     * @return Collection<int, UnitNight>
     */
    private function chargedNights(RoomRevenuePolicy $policy, ReservationUnit $unit, ?CarbonInterface $newDeparture): Collection
    {
        $nights = $unit->nights()->orderBy('date')->get();

        return match ($policy->applies_to) {
            PolicyScope::FirstNight => $nights->take(1),
            PolicyScope::WholeStay => $nights,
            PolicyScope::OneCharge => $nights->slice(-1),
            PolicyScope::UnusedNights => $this->unusedNights($nights, $newDeparture),
        };
    }

    /**
     * @param  Collection<int, UnitNight>  $nights
     * @return Collection<int, UnitNight>
     */
    private function unusedNights(Collection $nights, ?CarbonInterface $newDeparture): Collection
    {
        if ($newDeparture === null) {
            throw new InvalidArgumentException('An Unused nights policy needs the new departure date.');
        }

        $cutoff = $newDeparture->toDateString();

        return $nights->filter(fn (UnitNight $night): bool => $night->date->toDateString() >= $cutoff)->values();
    }

    /**
     * The rate the percent is taken of. A current rate that cannot be quoted falls back to the booked rate.
     *
     * @param  Collection<int, UnitNight>  $nights
     */
    private function baseAmount(RoomRevenuePolicy $policy, ReservationUnit $unit, Collection $nights, Money $zero): Money
    {
        $booked = $nights->reduce(fn (Money $sum, UnitNight $night): Money => $sum->plus($night->amount), $zero);

        if ($policy->percent_basis !== PolicyRateBasis::CurrentRate || $nights->isEmpty()) {
            return $booked;
        }

        $quote = $this->rateQuoteService->quote(new RateQuoteRequest(
            $unit->ratePlan()->firstOrFail(),
            $unit->roomType()->firstOrFail(),
            $nights->first()->date,
            $nights->last()->date->addDay(),
            $unit->adults,
            array_values($unit->childAges()->pluck('age')->map(fn (mixed $age): int => (int) $age)->all()),
            1,
        ));

        return $quote->isRefused() ? $booked : $quote->total;
    }
}
