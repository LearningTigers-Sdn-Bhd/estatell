<?php

namespace App\Enums;

/**
 * Which Curlec keys a booking website takes payments with. Test uses Curlec's test system, so no real money moves, and
 * each booking it makes is marked Test. Every payment keeps the mode it was made in, so its refund uses the same keys.
 * See docs/29-booking-engine/04-payment.md#payment-mode.
 */
enum PaymentMode: string
{
    case Test = 'test';
    case Live = 'live';

    public function label(): string
    {
        return match ($this) {
            self::Test => __('Test payments'),
            self::Live => __('Live payments'),
        };
    }

    /**
     * Test is a warning, because guests can book without paying real money.
     */
    public function tone(): Tone
    {
        return match ($this) {
            self::Test => Tone::Warning,
            self::Live => Tone::Success,
        };
    }
}
