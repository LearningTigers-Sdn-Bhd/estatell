<?php

namespace App\Data\Payments;

use App\Models\PaymentMethod;
use Spatie\LaravelData\Data;

/** A payment method staff can take money with on the Record payment Dialog. */
class PaymentMethodOptionData extends Data
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
