<?php

namespace App\Actions\BookingEngine;

use App\Models\BookingHold;
use Brick\Money\Money;

/**
 * What `CreateBookingHold` returns: the hold and the amount to pay at the property, or a stable refusal code with a
 * message that is safe to show. `currentTotal` comes with `price_changed`.
 */
class BookingHoldResult
{
    public const string REFUSAL_GUEST_IP_REQUIRED = 'guest_ip_required';

    public const string REFUSAL_OUTSIDE_WINDOW = 'outside_window';

    public const string REFUSAL_PLAN_NOT_AVAILABLE = 'plan_not_available';

    public const string REFUSAL_HOLD_LIMIT_REACHED = 'hold_limit_reached';

    public const string REFUSAL_PRICE_CHANGED = 'price_changed';

    private function __construct(
        public readonly ?BookingHold $hold,
        public readonly ?Money $deskTotal,
        public readonly ?string $refusal,
        public readonly ?string $message,
        public readonly ?string $currentTotal,
    ) {}

    public static function held(BookingHold $hold, Money $deskTotal): self
    {
        return new self($hold, $deskTotal, null, null, null);
    }

    public static function refused(string $refusal, string $message, ?string $currentTotal = null): self
    {
        return new self(null, null, $refusal, $message, $currentTotal);
    }
}
