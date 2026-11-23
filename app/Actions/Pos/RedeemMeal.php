<?php

namespace App\Actions\Pos;

use App\Enums\UnitStatus;
use App\Models\MealPeriod;
use App\Models\MealRedemption;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Pos\DailyMealCheckInList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Check off guests for an included meal. The unit row is locked before the redeemed count is read, so two hosts tapping
 * one room together can never go past the entitled guests. A unit must be CheckedIn, or CheckedOut on its departure date.
 * Checks, in order: `unit_not_eligible`, `meal_period_not_found`, `guest_count_invalid` (1 to 99), `not_entitled`,
 * `exceeds_entitlement`. A refusal writes and logs nothing. See docs/33-pos/02-meal-check-in.md.
 */
class RedeemMeal
{
    public function __construct(private DailyMealCheckInList $list) {}

    public function __invoke(User $actor, ReservationUnit $unit, MealPeriod $period, CarbonImmutable $serviceDate, int $guests, ?string $notes = null): MealRedemptionResult
    {
        return DB::transaction(function () use ($actor, $unit, $period, $serviceDate, $guests, $notes): MealRedemptionResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $day = $serviceDate->toDateString();
            $eligible = ($actor->is_admin || $locked->property_id === $actor->property_id)
                && ($locked->status === UnitStatus::CheckedIn
                    || ($locked->status === UnitStatus::CheckedOut && $locked->departure_date->toDateString() === $day));

            if (! $eligible) {
                return MealRedemptionResult::refused(MealRedemptionResult::REFUSAL_UNIT_NOT_ELIGIBLE);
            }

            if ($period->property_id !== $locked->property_id) {
                return MealRedemptionResult::refused(MealRedemptionResult::REFUSAL_MEAL_PERIOD_NOT_FOUND);
            }

            if ($guests < 1 || $guests > 99) {
                return MealRedemptionResult::refused(MealRedemptionResult::REFUSAL_GUEST_COUNT_INVALID);
            }

            $entitled = $this->list->entitledGuests($locked, $period, $serviceDate);

            if ($entitled < 1) {
                return MealRedemptionResult::refused(MealRedemptionResult::REFUSAL_NOT_ENTITLED);
            }

            $already = (int) MealRedemption::query()
                ->where('reservation_unit_id', $locked->id)
                ->where('meal_period_id', $period->id)
                ->whereDate('service_date', $day)
                ->active()
                ->sum('redeemed_guests');

            if ($already + $guests > $entitled) {
                return MealRedemptionResult::refused(MealRedemptionResult::REFUSAL_EXCEEDS_ENTITLEMENT);
            }

            $redemption = new MealRedemption([
                'reservation_unit_id' => $locked->id,
                'meal_period_id' => $period->id,
                'service_date' => $day,
                'redeemed_guests' => $guests,
                'redeemed_by_user_id' => $actor->id,
                'redeemed_at' => now(),
                'notes' => filled($notes) ? trim((string) $notes) : null,
            ]);
            $redemption->property_id = $locked->property_id;
            $redemption->save();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('meals.redeemed')
                ->withProperties(['property_id' => $locked->property_id, 'reservation_unit_id' => $locked->id, 'meal_period_id' => $period->id, 'service_date' => $day, 'guests' => $guests, 'meal_redemption_id' => $redemption->id])
                ->log('Meal redeemed');

            return MealRedemptionResult::done($redemption);
        });
    }
}
