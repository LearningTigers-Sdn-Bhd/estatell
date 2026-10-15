<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\StepState;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;

/**
 * Done when at least one active charge item has a default amount or a tax. Optional, so it never blocks the wizard;
 * "Skip" makes the step Done through OnboardingProgress.
 */
class ChargeItemsCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        return $property->chargeItems()
            ->active()
            ->where(fn (Builder $query) => $query
                ->whereNotNull('default_amount')
                ->orWhereHas('transactionCode.applicableTaxes'))
            ->exists()
            ? StepState::Done
            : StepState::NotStarted;
    }
}
