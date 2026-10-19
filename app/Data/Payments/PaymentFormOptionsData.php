<?php

namespace App\Data\Payments;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * The option list of the Record payment Dialog: the methods in position order (active, not City ledger or OTA
 * collected, plus Old system for `folio.manual` inside the migration window) and the business date, the last day an Old system
 * payment may be dated. The purposes are per folio, on `FolioData`.
 */
class PaymentFormOptionsData extends Data
{
    /**
     * @param  list<PaymentMethodOptionData>  $methods
     */
    public function __construct(
        #[DataCollectionOf(PaymentMethodOptionData::class)]
        public array $methods,
        public ?string $businessDate = null,
    ) {}

    /**
     * @param  list<PaymentMethodOptionData>  $methods
     */
    public static function forMethods(array $methods, ?string $businessDate = null): self
    {
        return new self($methods, $businessDate);
    }
}
