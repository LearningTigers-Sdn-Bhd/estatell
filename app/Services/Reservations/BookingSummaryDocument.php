<?php

namespace App\Services\Reservations;

use App\Enums\Currency;
use App\Services\Folio\DocumentLineGroups;
use App\Services\Folio\DocumentPayment;
use Carbon\CarbonImmutable;

/**
 * Everything the booking summary PDF prints, worked out by `BookingSummary`, so the view only prints. Without prices
 * `lines`, `paid`, `billed`, `balanceDue`, `balanceInWords` and `paymentDetails` are null and `payments` is empty.
 * `stamp` is CANCELLED, NO-SHOW or null. `balanceDue` is signed (below 0 is a credit) and null when nothing is charged and nothing is paid. `rooms` lists every unit, with its status at the end for a Cancelled or NoShow unit. Amounts are decimal strings with two decimals.
 */
final readonly class BookingSummaryDocument
{
    /**
     * @param  list<array{label: string, value: string}>  $strip  The info strip cells that have a value.
     * @param  list<string>  $rooms  One line per live unit.
     * @param  list<DocumentPayment>  $payments
     * @param  list<string>  $cancellationPolicy  The policy texts, one paragraph each; empty when the section is left out.
     */
    public function __construct(
        public string $reference,
        public CarbonImmutable $asAt,
        public Currency $currency,
        public ?string $stamp,
        public bool $showPrices,
        public array $strip,
        public array $rooms,
        public ?DocumentLineGroups $lines,
        public array $payments,
        public ?string $paid,
        public ?string $billed,
        public ?string $balanceDue,
        public ?string $balanceInWords,
        public array $cancellationPolicy,
        public ?string $paymentDetails,
    ) {}

    public function hasCharges(): bool
    {
        return $this->lines !== null && ($this->lines->groups !== [] || $this->lines->taxes !== []);
    }

    public function total(): ?string
    {
        return $this->lines?->total;
    }
}
