<?php

namespace App\Enums;

/**
 * How an admin or the owner closed a go-live request. A pending request has no decision.
 */
enum GoLiveDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Approved => __('Approved'),
            self::Rejected => __('Sent back'),
            self::Withdrawn => __('Withdrawn'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Approved => Tone::Success,
            self::Rejected => Tone::Warning,
            self::Withdrawn => Tone::Neutral,
        };
    }
}
