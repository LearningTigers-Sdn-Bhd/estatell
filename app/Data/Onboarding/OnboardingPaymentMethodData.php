<?php

namespace App\Data\Onboarding;

use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use Spatie\LaravelData\Data;

/**
 * One saved payment method as a row of the onboarding Payment methods table. City ledger stays off here: Companies
 * turns it on, so the row shows no switch and no reference box.
 */
class OnboardingPaymentMethodData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public PaymentMethodType $type,
        public string $typeLabel,
        public bool $active,
        public bool $requiresReference,
        public bool $isEditable,
    ) {}

    public static function fromModel(PaymentMethod $paymentMethod): self
    {
        return new self(
            id: $paymentMethod->id,
            name: $paymentMethod->name,
            type: $paymentMethod->type,
            typeLabel: $paymentMethod->type->label(),
            active: $paymentMethod->active,
            requiresReference: $paymentMethod->requires_reference,
            isEditable: $paymentMethod->type !== PaymentMethodType::DirectBill,
        );
    }
}
