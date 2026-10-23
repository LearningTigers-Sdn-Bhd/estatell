<?php

namespace App\Enums;

/**
 * Where a property stands with its accounting connection. Worked out from the connection, never stored.
 */
enum AccountingSetupStatus: string
{
    case NotConnected = 'not-connected';
    case NeedsAttention = 'needs-attention';
    case Ready = 'ready';
    case Live = 'live';

    public function label(): string
    {
        return match ($this) {
            self::NotConnected => __('Not connected'),
            self::NeedsAttention => __('Needs attention'),
            self::Ready => __('Ready'),
            self::Live => __('Live'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::NotConnected => Tone::Neutral,
            self::NeedsAttention => Tone::Danger,
            self::Ready => Tone::Info,
            self::Live => Tone::Success,
        };
    }
}
