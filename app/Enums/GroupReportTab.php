<?php

namespace App\Enums;

/**
 * The tabs of Group reports (`?tab`). By property and By date add up History & forecast across the group's
 * properties; Bookings lists Rooms on books › Changes of all of them. See docs/03-staff/05-group-mode.md.
 */
enum GroupReportTab: string
{
    case ByProperty = 'by-property';
    case ByDate = 'by-date';
    case Bookings = 'bookings';

    public function label(): string
    {
        return match ($this) {
            self::ByProperty => __('By property'),
            self::ByDate => __('By date'),
            self::Bookings => __('Bookings'),
        };
    }

    /**
     * Whether the tab opens on the whole month of today (the figures) or on today alone (the bookings made).
     */
    public function opensOnMonth(): bool
    {
        return $this !== self::Bookings;
    }
}
