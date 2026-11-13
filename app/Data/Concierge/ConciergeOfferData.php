<?php

namespace App\Data\Concierge;

use App\Services\Kiosk\KioskWalkInOffer;
use Spatie\LaravelData\Data;

/**
 * One room a walk-in can book: the room type and the server's price for the whole stay.
 */
class ConciergeOfferData extends Data
{
    public function __construct(
        public int $roomTypeId,
        public string $name,
        public string $total,
        public string $currency,
    ) {}

    public static function fromOffer(KioskWalkInOffer $offer): self
    {
        return new self($offer->roomType->id, $offer->roomType->name, (string) $offer->total->getAmount(), $offer->total->getCurrency()->getCurrencyCode());
    }
}
