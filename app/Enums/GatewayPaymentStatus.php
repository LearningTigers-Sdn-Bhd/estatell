<?php

namespace App\Enums;

/**
 * The status of an online payment attempt. Failed can still become Paid, because a guest retries on the same order.
 * See docs/29-booking-engine/04-payment.md.
 */
enum GatewayPaymentStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Failed = 'failed';

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Created => [self::Paid, self::Failed],
            self::Failed => [self::Paid],
            self::Paid => [],
        }, true);
    }
}
