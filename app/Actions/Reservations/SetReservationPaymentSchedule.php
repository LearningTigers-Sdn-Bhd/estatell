<?php

namespace App\Actions\Reservations;

use App\Enums\UnitStatus;
use App\Models\Reservation;
use App\Services\CityLedger\AgentPaymentTerms;
use App\Services\Reservations\AgentPaymentSchedule;
use Carbon\CarbonImmutable;

class SetReservationPaymentSchedule
{
    public function __construct(
        private AgentPaymentTerms $terms,
        private AgentPaymentSchedule $schedule,
    ) {}

    /**
     * Give a reservation its agent payment deadlines when its company pays per booking: the deposit share, when the
     * deposit is due and when the whole booking is due, from the terms the company follows and the earliest arrival.
     * A reservation that has deadlines keeps them, so a later change of terms never moves a promise. A reservation
     * with no company, or a company with no terms, gets none. `CreateReservation` (desk and portal, not channels) and a
     * company picked later call it. Returns whether deadlines were set.
     */
    public function __invoke(Reservation $reservation, ?CarbonImmutable $bookedAt = null): bool
    {
        if ($reservation->deposit_due_at !== null || $reservation->company_id === null) {
            return false;
        }

        $reservation->loadMissing(['company', 'property.settings']);
        $settings = $reservation->property->settings;
        $terms = $settings === null ? null : $this->terms->for($reservation->company, $settings);
        $arrival = $reservation->units()
            ->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->min('arrival_date');

        if ($terms === null || $arrival === null) {
            return false;
        }

        $deadlines = $this->schedule->deadlines($terms, $settings, $bookedAt ?? now()->toImmutable(), CarbonImmutable::parse($arrival));
        $reservation->forceFill($deadlines)->save();

        return true;
    }
}
