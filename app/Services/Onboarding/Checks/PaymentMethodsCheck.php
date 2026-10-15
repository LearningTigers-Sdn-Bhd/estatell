<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\PaymentMethodType;
use App\Enums\StepState;
use App\Models\Property;

/**
 * Done when at least one method of an addable type (Cash, Card, Bank transfer, E-wallet) is active. City ledger and OTA
 * collected do not count. The seeded methods start active, so a new property shows Done before the owner opens the step.
 */
class PaymentMethodsCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        $addable = array_map(fn (PaymentMethodType $type): string => $type->value, PaymentMethodType::addableCases());

        return $property->paymentMethods()->active()->whereIn('type', $addable)->exists()
            ? StepState::Done
            : StepState::NotStarted;
    }
}
