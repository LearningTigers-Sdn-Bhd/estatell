<?php

namespace App\Actions\Pos;

use App\Models\MealRedemption;

/**
 * The redemption, or a refusal code from `RedeemMeal` or `UndoMealRedemption`.
 */
class MealRedemptionResult
{
    public const REFUSAL_UNIT_NOT_ELIGIBLE = 'unit_not_eligible';

    public const REFUSAL_MEAL_PERIOD_NOT_FOUND = 'meal_period_not_found';

    public const REFUSAL_GUEST_COUNT_INVALID = 'guest_count_invalid';

    public const REFUSAL_NOT_ENTITLED = 'not_entitled';

    public const REFUSAL_EXCEEDS_ENTITLEMENT = 'exceeds_entitlement';

    public const REFUSAL_ALREADY_UNDONE = 'already_undone';

    public const REFUSAL_SERVICE_DATE_CLOSED = 'service_date_closed';

    private function __construct(public readonly ?MealRedemption $redemption, public readonly ?string $refusal) {}

    public static function done(MealRedemption $redemption): self
    {
        return new self($redemption, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
