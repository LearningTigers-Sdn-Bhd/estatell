<?php

namespace App\Services\CityLedger;

/**
 * Everything the AR receipt PDF shows, already formatted, so the Blade view does no lookups. `total` is a decimal
 * string with two places.
 */
class ArReceiptDocument
{
    /**
     * @param  list<string>  $addressLines
     * @param  list<string>  $contactLines
     * @param  list<string>  $registrationLines
     */
    public function __construct(
        public readonly string $propertyName,
        public readonly array $addressLines,
        public readonly array $contactLines,
        public readonly array $registrationLines,
        public readonly string $receiptNumber,
        public readonly string $receivedOn,
        public readonly string $companyName,
        public readonly string $method,
        public readonly ?string $reference,
        public readonly ?string $notes,
        public readonly string $currency,
        public readonly string $total,
        public readonly string $receivedBy,
        public readonly bool $isVoid,
    ) {}
}
