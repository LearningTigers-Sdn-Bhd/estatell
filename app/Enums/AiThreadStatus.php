<?php

namespace App\Enums;

enum AiThreadStatus: string
{
    case Open = 'open';
    case Escalated = 'escalated';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Escalated => __('Handed over'),
            self::Closed => __('Closed'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Open => Tone::Info,
            self::Escalated => Tone::Warning,
            self::Closed => Tone::Neutral,
        };
    }

    /**
     * Open hands over or closes. A handed-over thread closes, or goes back to Open when staff resolve it. A closed thread
     * stays closed: a new message starts a new thread.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Open => in_array($next, [self::Escalated, self::Closed], true),
            self::Escalated => in_array($next, [self::Open, self::Closed], true),
            self::Closed => false,
        };
    }
}
