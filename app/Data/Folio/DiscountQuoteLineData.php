<?php

namespace App\Data\Folio;

use App\Models\FolioLine;
use Spatie\LaravelData\Data;

/**
 * One eligible charge in a discount quote. `netAmount` is the charge line amount. `share` is the part of the discount
 * that lands on this charge, or null while the quote has no valid amount. See docs/13-folio/04-folio-discounts.md.
 */
class DiscountQuoteLineData extends Data
{
    public function __construct(
        public int $folioLineId,
        public string $description,
        public string $netAmount,
        public ?string $share,
    ) {}

    public static function fromLine(FolioLine $line, ?string $share): self
    {
        return new self($line->id, $line->description, $line->amount, $share);
    }
}
