<?php

namespace App\Enums;

enum AccountingSyncStatus: string
{
    case Pending = 'pending';
    case Pushed = 'pushed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Pushed => __('Pushed'),
            self::Failed => __('Failed'),
            self::Skipped => __('Nothing to push'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Pending => Tone::Info,
            self::Pushed => Tone::Success,
            self::Failed => Tone::Danger,
            self::Skipped => Tone::Neutral,
        };
    }

    /**
     * A pushed or skipped date is final. A failed one can be pushed again.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending, self::Failed => true,
            self::Pushed, self::Skipped => false,
        };
    }
}
