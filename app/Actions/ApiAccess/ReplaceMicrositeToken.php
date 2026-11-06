<?php

namespace App\Actions\ApiAccess;

use App\Enums\BookingClientType;
use App\Models\BookingClient;
use Illuminate\Support\Facades\DB;

/**
 * Give the active microsite client a new token, and return its plain text, which is never stored or shown again. The
 * client row stays, so its holds and bookings stay reachable. The old token stops working at once. Returns null when
 * there is no active microsite client. See docs/28-api-access/04-booking-engine-microsite.md.
 */
class ReplaceMicrositeToken
{
    public function __invoke(): ?string
    {
        return DB::transaction(function (): ?string {
            $client = BookingClient::query()->where('type', BookingClientType::Microsite)->whereNull('revoked_at')->lockForUpdate()->first();

            if ($client === null) {
                return null;
            }

            $client->tokens()->delete();
            $client->forceFill(['last_used_at' => null])->save();

            $token = $client->createToken($client->name, BookingClientType::Microsite->abilities())->plainTextToken;

            activity()
                ->performedOn($client)
                ->event('booking-api.microsite-token-replaced')
                ->log('Booking microsite token replaced');

            return $token;
        });
    }
}
