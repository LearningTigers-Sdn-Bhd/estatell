<?php

namespace App\Enums;

/**
 * What a booking revision from the channel manager asks for. Channex calls the cancelled kind `cancelled`.
 */
enum RevisionKind: string
{
    case New = 'new';
    case Modified = 'modified';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => __('New'),
            self::Modified => __('Modified'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::New => Tone::Success,
            self::Modified => Tone::Info,
            self::Cancelled => Tone::Danger,
        };
    }
}
