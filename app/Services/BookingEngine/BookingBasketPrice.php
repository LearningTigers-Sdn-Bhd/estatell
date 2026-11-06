<?php

namespace App\Services\BookingEngine;

use Brick\Money\Money;

/**
 * What `BookingBasket` works out: one offer for each line, and the totals, or a stable refusal code with a message that is
 * safe to show. The offers and totals are null when `refusal` is set.
 */
class BookingBasketPrice
{
    /**
     * @param  list<BookingOffer>|null  $offers  In line order.
     */
    private function __construct(
        public readonly ?array $offers,
        public readonly ?Money $onlineTotal,
        public readonly ?Money $deskTotal,
        public readonly ?string $refusal,
        public readonly ?string $message,
    ) {}

    /**
     * @param  list<BookingOffer>  $offers
     */
    public static function priced(array $offers, Money $onlineTotal, Money $deskTotal): self
    {
        return new self($offers, $onlineTotal, $deskTotal, null, null);
    }

    public static function refused(string $refusal, string $message): self
    {
        return new self(null, null, null, $refusal, $message);
    }
}
