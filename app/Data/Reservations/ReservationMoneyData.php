<?php

namespace App\Data\Reservations;

use App\Services\Reservations\BookingSummaryDocument;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * The full money figures of the reservation page's summary card, taken from the booking summary so the card and the
 * PDF show the same numbers. `stillToPay` is never negative: `isCredit` says the guest is owed that amount instead.
 */
class ReservationMoneyData extends Data
{
    public function __construct(
        public string $total,
        public string $paid,
        public string $billedToCompany,
        public string $stillToPay,
        public bool $isCredit,
    ) {}

    public static function fromSummary(BookingSummaryDocument $summary): self
    {
        $due = BigDecimal::of($summary->balanceDue ?? '0');

        return new self(
            total: $summary->total() ?? '0.00',
            paid: $summary->paid ?? '0.00',
            billedToCompany: $summary->billed ?? '0.00',
            stillToPay: $due->abs()->toScale(2)->__toString(),
            isCredit: $due->isNegative(),
        );
    }
}
