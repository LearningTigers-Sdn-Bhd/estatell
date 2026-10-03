<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * The result of `DiscountQuote`: the eligible charges, their subtotal, the discount amount and the fingerprint that
 * `ApplyDiscount` compares. `amount` is null when the discount needs an amount that was not given. `amountRefusal` is
 * the `PostingResult` refusal code for the amount (`amount_required`, `amount_invalid`, `amount_override_not_allowed`),
 * or null when the amount is fine. See docs/13-folio/04-folio-discounts.md.
 */
class DiscountQuoteData extends Data
{
    /**
     * @param  list<DiscountQuoteLineData>  $lines
     */
    public function __construct(
        #[DataCollectionOf(DiscountQuoteLineData::class)]
        public array $lines,
        public string $subtotal,
        public ?string $amount,
        public ?string $amountRefusal,
        public string $fingerprint,
    ) {}
}
