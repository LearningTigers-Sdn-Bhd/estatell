<?php

namespace App\Services\CityLedger;

use App\Enums\Currency;
use App\Services\Folio\DocumentLineGroups;
use App\Services\Folio\DocumentPayment;

/**
 * Everything the AR invoice PDF shows, already formatted, so the Blade view does no lookups. Amounts are decimal
 * strings with two places. A credit note uses `billToLines`, the dates, `creditFor`, `reason` and `total`, and keeps
 * its own layout. An invoice uses `strip` (the info strip cells that have a value), `rooms` (the Rooms block, empty when
 * Room and guest on lines is on), `lines` (null without a folio), `payments` (the guest payments at the hotel, the city
 * ledger line left out), `paid`, `amountDue`, `amountInWords` and `paymentDetails`.
 */
final readonly class ArInvoiceDocument
{
    /**
     * @param  list<string>  $billToLines
     * @param  list<array{label: string, lines: list<string>}>  $strip
     * @param  list<string>  $rooms
     * @param  list<DocumentPayment>  $payments
     */
    public function __construct(
        public string $title,
        public string $number,
        public bool $isCreditNote,
        public string $companyName,
        public array $billToLines,
        public string $issuedOn,
        public ?string $dueOn,
        public ?string $creditFor,
        public ?string $reason,
        public ?Currency $currency,
        public string $total,
        public bool $isVoid,
        public array $strip = [],
        public array $rooms = [],
        public ?DocumentLineGroups $lines = null,
        public array $payments = [],
        public string $paid = '0.00',
        public string $amountDue = '0.00',
        public ?string $amountInWords = null,
        public ?string $paymentDetails = null,
    ) {}

    public function currencyCode(): string
    {
        return $this->currency?->value ?? '';
    }
}
