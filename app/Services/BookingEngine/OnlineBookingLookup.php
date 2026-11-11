<?php

namespace App\Services\BookingEngine;

use App\Enums\BookingHoldStatus;
use App\Models\BookingClient;
use App\Models\BookingHold;
use App\Models\Property;
use App\Models\Reservation;

/**
 * Finds a guest's online booking from its short code and email. The email is the booker's, not the hold's, because
 * `PurgeBookingHoldGuestDetails` empties a confirmed hold after 30 days and a guest can book further ahead than that. A
 * client reaches only the bookings it made. See docs/29-booking-engine/02-api.md#find-a-booking.
 */
class OnlineBookingLookup
{
    public function find(BookingClient $client, Property $property, string $shortCode, string $email): ?BookingHold
    {
        $reservation = Reservation::query()
            ->with('booker')
            ->where('property_id', $property->id)
            ->where('short_code', mb_strtoupper(trim($shortCode)))
            ->first();

        $bookerEmail = mb_strtolower(trim((string) $reservation?->booker?->email));

        if ($bookerEmail === '' || ! hash_equals($bookerEmail, mb_strtolower(trim($email)))) {
            return null;
        }

        return BookingHold::query()
            ->where('booking_client_id', $client->id)
            ->where('property_id', $property->id)
            ->where('reservation_id', $reservation->id)
            ->where('status', BookingHoldStatus::Confirmed)
            ->first();
    }
}
