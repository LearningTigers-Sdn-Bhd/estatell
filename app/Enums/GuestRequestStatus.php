<?php

namespace App\Enums;

enum GuestRequestStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::InProgress => __('In progress'),
            self::Resolved => __('Resolved'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Open => Tone::Warning,
            self::InProgress => Tone::Info,
            self::Resolved => Tone::Success,
            self::Cancelled => Tone::Neutral,
        };
    }

    /**
     * Open to InProgress, Resolved or Cancelled; InProgress back to Open or on to Resolved or Cancelled; Resolved and
     * Cancelled only reopen.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Open => in_array($next, [self::InProgress, self::Resolved, self::Cancelled], true),
            self::InProgress => in_array($next, [self::Open, self::Resolved, self::Cancelled], true),
            self::Resolved, self::Cancelled => $next === self::Open,
        };
    }

    /**
     * Whether the request is still work to do.
     */
    public function isOpenWork(): bool
    {
        return $this === self::Open || $this === self::InProgress;
    }
}
