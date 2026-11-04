<?php

namespace App\Actions\Kiosk;

use App\Actions\Guests\SetGuestDetails;
use App\Models\ApiToken;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskStays;

class CorrectStayGuest
{
    public function __construct(private KioskStays $stays, private SetGuestDetails $setGuestDetails) {}

    /**
     * Apply what the guest corrected on the confirm screen. The guest's own value replaces the saved one; a missing
     * or empty field changes nothing. Returns false when the stay has no open guest.
     */
    public function __invoke(ApiToken $kiosk, ReservationUnit $stay, ?string $fullName, ?string $nationalityCode): bool
    {
        $guest = $this->stays->guest($stay);

        return $guest !== null && ($this->setGuestDetails)($guest, [
            'full_name' => $fullName,
            'nationality_code' => $nationalityCode,
        ], keepExisting: false, context: ['kiosk_id' => $kiosk->id]);
    }
}
