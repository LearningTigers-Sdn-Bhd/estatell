<?php

namespace App\Enums;

/**
 * Where an agent's payment proof stands: sent and waiting for staff, accepted (a payment was recorded from it) or
 * rejected with a reason.
 */
enum PaymentProofStatus: string
{
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => __('Proof received'),
            self::Accepted => __('Accepted'),
            self::Rejected => __('Rejected'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Submitted => Tone::Info,
            self::Accepted => Tone::Success,
            self::Rejected => Tone::Danger,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return $this === self::Submitted && $status !== self::Submitted;
    }
}
