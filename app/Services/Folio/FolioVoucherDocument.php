<?php

namespace App\Services\Folio;

/**
 * Everything the voucher PDF shows, already formatted, so the Blade view does no lookups. `rows` are the posted lines
 * in ledger order, then the Scheduled rows; the PDF does not tell them apart. `level` is 0 for a row with no parent
 * on the voucher and 1 or 2 for its discount and tax rows. Amounts are signed decimal strings with two places.
 * `total` is the sum of all rows. `folioNumber` is null for a unit that has no folio yet.
 */
class FolioVoucherDocument
{
    /**
     * @param  list<string>  $addressLines
     * @param  list<string>  $contactLines
     * @param  list<string>  $registrationLines
     * @param  list<array{date: string, description: string, level: int, amount: string}>  $rows
     */
    public function __construct(
        public readonly string $propertyName,
        public readonly array $addressLines,
        public readonly array $contactLines,
        public readonly array $registrationLines,
        public readonly string $issuedOn,
        public readonly string $reservationReference,
        public readonly ?string $folioNumber,
        public readonly string $guestName,
        public readonly ?string $nationality,
        public readonly string $stay,
        public readonly string $currency,
        public readonly array $rows,
        public readonly string $total,
    ) {}
}
