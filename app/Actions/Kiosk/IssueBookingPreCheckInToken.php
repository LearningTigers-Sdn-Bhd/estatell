<?php

namespace App\Actions\Kiosk;

use App\Models\BookingPreCheckInToken;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;

class IssueBookingPreCheckInToken
{
    /** The plaintext link is returned once; only its token hash is stored. */
    public function __invoke(ReservationUnit $stay): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = CarbonImmutable::parse($stay->departure_date->toDateString(), $stay->property->settings->time_zone)
            ->addDay()->startOfDay()->utc();

        BookingPreCheckInToken::query()->create([
            'property_id' => $stay->property_id,
            'reservation_unit_id' => $stay->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ]);

        return url('/c/'.$token);
    }
}
