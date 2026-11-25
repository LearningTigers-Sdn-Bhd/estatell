<?php

namespace App\Data\Concierge;

use App\Models\ConciergeQuote;
use App\Services\Kiosk\KioskWalkInOffer;
use Spatie\LaravelData\Data;

/**
 * A quote link as its page shows it. `total` is the price now, null when the room can no longer be booked on that plan;
 * `quotedTotal` is the price the guest was told. See docs/35-ai-assistant/03-places-and-routes.md#quote-links.
 */
class ConciergeQuoteData extends Data
{
    public function __construct(
        public string $token,
        public string $roomTypeName,
        public string $ratePlanName,
        public string $arrivalDate,
        public string $departureDate,
        public int $nights,
        public int $adults,
        public string $quotedTotal,
        public ?string $total,
        public string $currency,
        public bool $expired,
    ) {}

    public static function fromQuote(ConciergeQuote $quote, ?KioskWalkInOffer $offer, string $currency): self
    {
        return new self(
            $quote->token,
            $quote->roomType->name,
            $quote->ratePlan->name,
            $quote->arrival_date->toDateString(),
            $quote->departure_date->toDateString(),
            (int) $quote->arrival_date->diffInDays($quote->departure_date),
            $quote->adults,
            (string) $quote->quoted_total,
            $offer === null ? null : (string) $offer->total->getAmount(),
            $currency,
            ! $quote->expires_at->isFuture(),
        );
    }
}
