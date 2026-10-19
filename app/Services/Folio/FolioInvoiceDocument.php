<?php

namespace App\Services\Folio;

use Brick\Money\Currency;
use Carbon\CarbonImmutable;

/**
 * Everything the folio invoice PDF prints, worked out by `FolioInvoice`, so the view only prints. `strip` holds the info
 * strip cells that have a value, each with its lines (Bill to has several). `rooms` is the Rooms block, empty when Room
 * and guest on lines is on. `balance` is signed and 0.00 on a closed folio. `totalInWords` and `showSignatureLines` follow
 * the Documents switches. `oldSystem` groups the lines copied from the old system, printed first under `oldTitle` with their own subtotal (null
 * when the folio has none); `lines` then holds the other lines and `total` is both together, so the balance adds up.
 * Amounts are decimal strings with two decimals.
 */
final readonly class FolioInvoiceDocument
{
    /**
     * @param  list<array{label: string, lines: list<string>}>  $strip
     * @param  list<string>  $rooms
     * @param  list<DocumentPayment>  $payments
     */
    public function __construct(
        public string $invoiceNumber,
        public CarbonImmutable $closedOn,
        public Currency $currency,
        public array $strip,
        public array $rooms,
        public DocumentLineGroups $lines,
        public array $payments,
        public string $paid,
        public string $billed,
        public string $balance,
        public ?string $totalInWords,
        public bool $showSignatureLines,
        public ?DocumentLineGroups $oldSystem = null,
        public ?string $oldTitle = null,
        public string $total = '0.00',
    ) {}

    public function hasCharges(): bool
    {
        return $this->lines->groups !== [] || $this->lines->taxes !== [] || $this->oldSystem !== null;
    }
}
