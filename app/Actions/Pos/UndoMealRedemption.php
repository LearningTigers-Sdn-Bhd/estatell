<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Models\MealRedemption;
use App\Models\PosOrderLine;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Undo one check-in. The row stays with `undone_at` set. Refused when it is already undone (`already_undone`), a POS order
 * line still uses it (`redeemed_in_pos`) or its service date is before the property's current business date (`service_date_closed`). A refusal writes nothing.
 * See docs/33-pos/02-meal-check-in.md.
 */
class UndoMealRedemption
{
    public function __construct(private CurrentBusinessDate $currentBusinessDate) {}

    public function __invoke(User $actor, MealRedemption $redemption): MealRedemptionResult
    {
        return $this->undo($actor, $redemption, false);
    }

    /**
     * The POS refund's way in, for a meal a POS order line used. The meal check-in page refuses such a meal
     * (`redeemed_in_pos`), so a served breakfast cannot be handed back without refunding its line.
     */
    public function forPos(User $actor, MealRedemption $redemption): MealRedemptionResult
    {
        return $this->undo($actor, $redemption, true);
    }

    private function undo(User $actor, MealRedemption $redemption, bool $fromPos): MealRedemptionResult
    {
        return DB::transaction(function () use ($actor, $redemption, $fromPos): MealRedemptionResult {
            $current = MealRedemption::query()->lockForUpdate()->findOrFail($redemption->id);

            if ($current->undone_at !== null) {
                return MealRedemptionResult::refused(MealRedemptionResult::REFUSAL_ALREADY_UNDONE);
            }

            if (! $fromPos && PosOrderLine::query()->where('meal_redemption_id', $current->id)->where('kitchen_status', '!=', PosKitchenStatus::Voided->value)->exists()) {
                return MealRedemptionResult::refused(MealRedemptionResult::REFUSAL_REDEEMED_IN_POS);
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
