<?php

namespace App\Data\Payments;

use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use Spatie\LaravelData\Data;

/** A payment method staff can take money with on the Record payment Dialog. `isOldSystem` marks the migration method: it takes a date and a reference, and no receipt. `isOtaCollected` marks money the OTA took: only the Record payment Dialog offers it. `type` is the `PaymentMethodType` value: the Refund Dialog offers Cash only for a cash payment. */
class PaymentMethodOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $requiresReference,
        public bool $isOldSystem = false,
        public ?string $type = null,
        public bool $isOtaCollected = false,
    ) {}

    public static function fromModel(PaymentMethod $method): self
    {
        return new self($method->id, $method->name, $method->requires_reference, $method->type === PaymentMethodType::OldSystem, $method->type->value, $method->type === PaymentMethodType::OtaCollected);
    }
}
