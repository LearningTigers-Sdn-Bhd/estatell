<?php

namespace App\Http\Requests\Reservations;

use App\Http\Requests\Guests\GuestRequest;

class UpdateUnitGuestRequest extends GuestRequest
{
    /**
     * The guest's profile and identity use the same validation as the guest Details form.
     * Reservation route middleware also requires reservations.manage and guests.manage.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return $this->profileAttributes();
    }
}
