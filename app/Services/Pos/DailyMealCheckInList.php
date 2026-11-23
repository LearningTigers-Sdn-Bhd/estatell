<?php

namespace App\Services\Pos;

use App\Data\Meals\MealEntitlementData;
use App\Data\Meals\MealInclusionData;
use App\Data\Pos\MealCheckInListData;
use App\Data\Pos\MealCheckInRow;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Models\MealPeriod;
use App\Models\MealRedemption;
use App\Models\Property;
use App\Models\RatePlanInclusion;
use App\Models\ReservationUnit;
use App\Services\Meals\MealEntitlementResolver;
use App\Services\Reservations\ReservationRoomBox;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

/**
 * The rooms entitled to one meal period on one date, with how many guests already checked in. It reads only and uses
 * `MealEntitlementResolver`, the same source as the meal counts report. Checked-in units, plus checked-out units on their departure date (a departure-day breakfast, as `RedeemMeal` allows); Cancelled, No-show and
 * day use units never appear. Undone redemptions do not count. `entitledGuests()` is also what `RedeemMeal` caps on.
 * See docs/33-pos/02-meal-check-in.md.
 */
class DailyMealCheckInList
{
    public function __construct(
        private MealEntitlementResolver $resolver,
        private ReservationRoomBox $roomBox,
    ) {}

    public function forPeriod(Property $property, CarbonImmutable $date, MealPeriod $period, ?string $search = null): MealCheckInListData
    {
        $units = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where(fn ($status) => $status
                ->where('status', UnitStatus::CheckedIn)
                ->orWhere(fn ($left) => $left->where('status', UnitStatus::CheckedOut)->whereDate('departure_date', $date->toDateString())))
            ->where('stay_type', '!=', StayType::DayUse->value)
            ->whereDate('arrival_date', '<=', $date->toDateString())
            ->whereDate('departure_date', '>=', $date->toDateString())
            ->whereHas('ratePlan.inclusions', fn ($inclusions) => $inclusions->where('meal_period_id', $period->id))
            ->with(['ratePlan.inclusions.mealPeriod', 'reservation.booker', 'guests' => fn ($guests) => $guests->where('is_primary', true)->with('guest')])
            ->orderBy('id')
            ->get();

        $rooms = $this->roomBox->assignmentsForUnitsOn(array_values(array_map(intval(...), $units->modelKeys())), $date->toDateString());
        $redemptions = MealRedemption::query()
            ->where('property_id', $property->id)
            ->where('meal_period_id', $period->id)
            ->whereDate('service_date', $date->toDateString())
            ->whereIn('reservation_unit_id', $units->modelKeys())
            ->active()
            ->orderByDesc('id')
            ->get()
            ->groupBy('reservation_unit_id');

        $needle = $search === null ? '' : mb_strtolower(trim($search));
        $rows = [];

        foreach ($units as $unit) {
            $entitled = $this->entitledGuests($unit, $period, $date);

            if ($entitled < 1) {
                continue;
            }

            $unitRedemptions = $redemptions->get($unit->id, collect());
            $redeemed = (int) $unitRedemptions->sum('redeemed_guests');
            $guestName = $unit->guests->first()?->guest->full_name ?? $unit->reservation->booker->full_name;
            $row = new MealCheckInRow(
                $unit->id,
                $rooms[$unit->id] ?? '',
                $guestName,
                $unit->reservation->reference,
                $entitled,
                $redeemed,
                max(0, $entitled - $redeemed),
                $redeemed === 0 ? 'pending' : ($redeemed >= $entitled ? 'redeemed' : 'partial'),
                array_values($unitRedemptions->pluck('id')->map(intval(...))->all()),
            );

            if ($needle === '' || str_contains(mb_strtolower($row->roomNumber.' '.$row->guestName.' '.$row->reservationReference), $needle)) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (MealCheckInRow $a, MealCheckInRow $b): int => strnatcasecmp($a->roomNumber, $b->roomNumber));
        $collection = collect($rows);

        return new MealCheckInListData(
            (int) $collection->sum('entitledGuests'),
            (int) $collection->sum('redeemedGuests'),
            (int) $collection->sum('remainingGuests'),
            $rows,
        );
    }

    /**
     * How many guests of the unit are owed the period on the date, 0 when none.
     */
    public function entitledGuests(ReservationUnit $unit, MealPeriod $period, CarbonImmutable $date): int
    {
        if ($unit->stay_type === StayType::DayUse) {
            return 0;
        }

        $unit->loadMissing('ratePlan.inclusions.mealPeriod', 'property');
        $inclusions = $unit->ratePlan->inclusions
            ->filter(fn (RatePlanInclusion $inclusion): bool => $inclusion->meal_period_id === $period->id)
            ->map(fn (RatePlanInclusion $inclusion): MealInclusionData => MealInclusionData::fromInclusion($inclusion));
        $trim = Feature::for($unit->property)->active(Boats::class);

        $entitlements = $this->resolver->resolve(
            CarbonImmutable::parse($unit->arrival_date->toDateString()),
            CarbonImmutable::parse($unit->departure_date->toDateString()),
            $unit->adults + $unit->children,
            $inclusions,
            $trim ? $unit->arrival_time : null,
            $trim ? $unit->departure_time : null,
        );

        $match = $entitlements->first(fn (MealEntitlementData $entitlement): bool => $entitlement->date === $date->toDateString() && $entitlement->mealPeriodId === $period->id);

        return $match instanceof MealEntitlementData ? $match->guests : 0;
    }
}
