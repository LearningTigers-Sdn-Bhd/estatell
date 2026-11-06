<?php

namespace App\Actions\BookingEngine;

use App\Enums\BookingClientType;
use App\Models\BookingClient;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stop an API only property's token at once. The client row stays, so a new token later reaches the same holds and
 * bookings. Returns false, writing nothing, when the property has no active token.
 */
class RevokeBookingApiToken
{
    public function __invoke(User $actor, Property $property): bool
    {
        return DB::transaction(function () use ($actor, $property): bool {
            $client = BookingClient::query()
                ->where('type', BookingClientType::ApiOnly)
                ->where('property_id', $property->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($client === null) {
                return false;
            }

            $client->forceFill(['revoked_at' => now()])->save();
            $client->tokens()->delete();

            activity()
                ->performedOn($client)
                ->causedBy($actor)
                ->event('booking-website.api-token-revoked')
                ->withProperties(['property_id' => $property->id])
                ->log('Booking API token revoked');

            return true;
        });
    }
}
