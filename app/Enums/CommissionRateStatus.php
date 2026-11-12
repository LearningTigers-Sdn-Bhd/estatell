<?php

namespace App\Enums;

/**
 * Where a commission rate stands against today. Worked out on read, never stored.
 */
enum CommissionRateStatus: string
{
    case Scheduled = 'scheduled';
    case Current = 'current';
    case Past = 'past';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => __('Scheduled'),
            self::Current => __('Current'),
            self::Past => __('Past'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Scheduled => Tone::Info,
            self::Current => Tone::Success,
            self::Past => Tone::Neutral,
        };
    }
}
