<?php

namespace App\Enums;

/**
 * A front desk movement on the business date, shown as a tab on the reservation list. A reservation is in the tab
 * while one of its units still has to make the move: Arrivals are Confirmed units arriving on the date, Departures
 * are Checked in units departing on it. See docs/12-reservations/06-reservation-page.md.
 */
enum UnitMovement: string
{
    case Arrivals = 'arrivals';
    case Departures = 'departures';

    public function label(): string
    {
        return match ($this) {
            self::Arrivals => __('Arrivals'),
            self::Departures => __('Departures'),
        };
    }

    /**
     * The `reservation_units` date column the movement matches the business date on.
     */
    public function dateColumn(): string
    {
        return match ($this) {
            self::Arrivals => 'arrival_date',
            self::Departures => 'departure_date',
        };
    }

    /**
     * The unit status of a move still to do. A unit leaves the tab once checked in (Arrivals) or out (Departures).
     */
    public function pendingStatus(): UnitStatus
    {
        return match ($this) {
            self::Arrivals => UnitStatus::Confirmed,
            self::Departures => UnitStatus::CheckedIn,
        };
    }
}
