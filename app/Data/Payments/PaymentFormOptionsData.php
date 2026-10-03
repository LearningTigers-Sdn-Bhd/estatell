<?php

namespace App\Data\Payments;

use App\Enums\PaymentPurpose;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * The option lists of the Record payment Dialog: the methods in position order (active, not City ledger or OTA
 * collected) and the purposes.
 */
class PaymentFormOptionsData extends Data
{
    /**
     * @param  list<PaymentMethodOptionData>  $methods
     * @param  list<array{value: string, label: string}>  $purposes
     */
    public function __construct(
        #[DataCollectionOf(PaymentMethodOptionData::class)]
        public array $methods,
        public array $purposes,
    ) {}

    /**
     * @param  list<PaymentMethodOptionData>  $methods
     */
    public static function forMethods(array $methods): self
    {
        return new self($methods, PaymentPurpose::options());
    }
}
