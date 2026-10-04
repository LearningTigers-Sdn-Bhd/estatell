<?php

namespace App\Data\CityLedger;

use App\Models\PaymentMethod;
use Spatie\LaravelData\Data;

/**
 * A payment method staff can record an AR payment with.
 */
class ArPaymentMethodOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $requiresReference,
    ) {}

    public static function fromModel(PaymentMethod $method): self
    {
        return new self($method->id, $method->name, $method->requires_reference);
    }
}
