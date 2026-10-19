<?php

namespace App\Enums;

/**
 * A marker on a row of the folio page. The page draws it as an icon with the label in a tooltip.
 * See docs/13-folio/05-folio-page.md.
 */
enum FolioRowTag: string
{
    case Scheduled = 'scheduled';
    case Overridden = 'overridden';
    case Reversed = 'reversed';
    case Reversal = 'reversal';
    case Refund = 'refund';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => __('Scheduled, not posted yet'),
            self::Overridden => __('Price overridden'),
            self::Reversed => __('Reversed'),
            self::Reversal => __('Reversal of an earlier line'),
            self::Refund => __('Refund'),
            self::Manual => __('Manual entry'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Scheduled, self::Manual => Tone::Neutral,
            self::Overridden, self::Reversed, self::Reversal => Tone::Warning,
            self::Refund => Tone::Info,
        };
    }
}
