<?php

namespace App\Enums;

/**
 * The status of a refund asked of Curlec. Failed can go back to Pending, because staff can retry it.
 * See docs/29-booking-engine/05-cancel-and-refund.md#refund-status.
 */
enum GatewayRefundStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Refund pending'),
            self::Processed => __('Refunded'),
            self::Failed => __('Refund failed'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Pending => Tone::Warning,
            self::Processed => Tone::Success,
            self::Failed => Tone::Danger,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Pending => [self::Processed, self::Failed],
            self::Failed => [self::Pending],
            self::Processed => [],
        }, true);
    }
}
