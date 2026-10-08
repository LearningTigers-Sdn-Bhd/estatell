<?php

namespace App\Data\Payments;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * The option list of the Record payment Dialog: the methods in position order (active, not City ledger or OTA
 * collected). The purposes are per folio, on `FolioData`.
 */
class PaymentFormOptionsData extends Data
{
    /**
     * @param  list<PaymentMethodOptionData>  $methods
     */
    public function __construct(
        #[DataCollectionOf(PaymentMethodOptionData::class)]
        public array $methods,
    ) {}

    /**
     * @param  list<PaymentMethodOptionData>  $methods
     */
    public static function forMethods(array $methods): self
    {
        return new self($methods);
    }
}
