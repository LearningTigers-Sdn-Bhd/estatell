<?php

namespace App\Actions\Concierge;

use App\Models\ConciergeQuote;

/**
 * The outcome of making a quote link: the quote, or the refusal and nothing written. A refusal is one of `invalid_dates`,
 * `outside_window` or `no_room_available`.
 */
final readonly class ConciergeQuoteCreated
{
    private function __construct(public ?ConciergeQuote $quote, public ?string $refusal) {}

    public static function created(ConciergeQuote $quote): self
    {
        return new self($quote, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal);
    }
}
