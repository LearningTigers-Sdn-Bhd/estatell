<?php

namespace App\Services\Reservations;

use App\Models\Reservation;
use App\Services\Folio\ScheduledFolioRows;
use Brick\Math\BigDecimal;

/**
 * What the live units of a reservation will still charge: the nights, day use and add-ons not yet posted, with their
 * discounts and tax (SST, service charge, tourism tax), as the folio page shows them as Scheduled rows. Credit checks
 * use it, so a company's limit covers the whole bill and not only the room. A line already posted is on a folio, where
 * `CreditExposure` counts it. It is never below the room total of `StayTotal`, so a property missing its room charge
 * code (no Scheduled rows) is still checked. A decimal string with two places. It writes nothing.
 * See docs/15-city-ledger/04-credit-control-and-reports.md#credit-check.
 */
class StayChargeTotal
{
    public function __construct(private ScheduledFolioRows $scheduledFolioRows, private StayTotal $stayTotal) {}

    public function forReservation(Reservation $reservation): string
    {
        $reservation->loadMissing(['booker', 'property.settings']);
        $total = BigDecimal::zero();

        foreach ($this->scheduledFolioRows->forReservation($reservation->property, $reservation, []) as $block) {
            foreach ($block['rows'] as $row) {
                $total = $total->plus($row->amount);
            }
        }

        return (string) BigDecimal::max($total, BigDecimal::of($this->stayTotal->forReservation($reservation)))->toScale(2);
    }
}
