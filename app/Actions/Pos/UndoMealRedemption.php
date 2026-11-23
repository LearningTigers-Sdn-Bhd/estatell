<?php

namespace App\Actions\Pos;

use App\Models\MealRedemption;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Undo one check-in. The row stays with `undone_at` set. Refused when it is already undone (`already_undone`) or its
 * service date is before the property's current business date (`service_date_closed`). A refusal writes nothing.
 * See docs/33-pos/02-meal-check-in.md.
 */
class UndoMealRedemption
{
    public function __construct(private CurrentBusinessDate $currentBusinessDate) {}

    public function __invoke(User $actor, MealRedemption $redemption): MealRedemptionResult
    {
        return DB::transaction(function () use ($actor, $redemption): MealRedemptionResult {
            $current = MealRedemption::query()->lockForUpdate()->findOrFail($redemption->id);

            if ($current->undone_at !== null) {
                return MealRedemptionResult::refused(MealRedemptionResult::REFUSAL_ALREADY_UNDONE);
            }

            $businessDate = $this->currentBusinessDate->date($current->property);

            if ($businessDate === null || $current->service_date->toDateString() < $businessDate->toDateString()) {
                return MealRedemptionResult::refused(MealRedemptionResult::REFUSAL_SERVICE_DATE_CLOSED);
            }

            $current->forceFill(['undone_at' => now(), 'undone_by_user_id' => $actor->id])->save();

            activity()
                ->performedOn($current->reservationUnit->reservation)
                ->causedBy($actor)
                ->event('meals.redemption_undone')
                ->withProperties(['property_id' => $current->property_id, 'reservation_unit_id' => $current->reservation_unit_id, 'meal_redemption_id' => $current->id, 'guests' => $current->redeemed_guests])
                ->log('Meal redemption undone');

            return MealRedemptionResult::done($current);
        });
    }
}
