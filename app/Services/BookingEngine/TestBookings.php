<?php

namespace App\Services\BookingEngine;

use App\Enums\PaymentMode;
use App\Models\GatewayPayment;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether a reservation came from the booking website in Test payment mode. Its guest paid in Curlec's test system, so
 * no real money moved. Staff see a Test badge and cancel it after checking the flow.
 * See docs/29-booking-engine/04-payment.md#payment-mode.
 */
class TestBookings
{
    public function isTest(Reservation $reservation): bool
    {
        return GatewayPayment::query()
            ->where('property_id', $reservation->property_id)
            ->where('mode', PaymentMode::Test)
            ->whereHas('hold', fn (Builder $hold) => $hold->where('reservation_id', $reservation->id))
            ->exists();
    }
}
