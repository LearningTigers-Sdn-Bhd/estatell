<?php

namespace App\Services\CityLedger;

/**
 * Everything the AR invoice PDF shows, already formatted, so the Blade view does no lookups. Amounts are decimal
 * strings with two places. `lines` hold [date, description, amount]. `creditFor` is the invoice number a credit note
 * corrects, and `reason` is a credit note's reason.
 */
class ArInvoiceDocument
{
    /**
     * @param  list<string>  $addressLines
     * @param  list<string>  $contactLines
     * @param  list<string>  $registrationLines
     * @param  list<string>  $billToLines
     * @param  list<array{0: string, 1: string, 2: string}>  $lines
     */
    public function __construct(
        public readonly string $title,
        public readonly string $number,
        public readonly string $propertyName,
        public readonly array $addressLines,
        public readonly array $contactLines,
        public readonly array $registrationLines,
        public readonly string $companyName,
        public readonly array $billToLines,
        public readonly string $issuedOn,
        public readonly ?string $dueOn,
        public readonly ?string $folioNumber,
        public readonly ?string $creditFor,
        public readonly ?string $reason,
        public readonly string $currency,
        public readonly array $lines,
        public readonly string $total,
        public readonly bool $isVoid,
    ) {}
}
