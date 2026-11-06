<?php

namespace App\Actions\BookingEngine;

use App\Enums\BookingClientType;
use App\Models\BookingClient;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Give an API only property a new token, and return its plain text, which is never stored or shown again. The property
 * keeps one client row, so its holds and bookings stay reachable. An old token stops working at once.
 * See docs/29-booking-engine/09-api-only-mode.md#access.
 */
class IssueBookingApiToken
{
    public function __invoke(User $actor, Property $property): string
    {
        return DB::transaction(function () use ($actor, $property): string {
            $client = BookingClient::query()
                ->where('type', BookingClientType::ApiOnly)
                ->where('property_id', $property->id)
                ->lockForUpdate()
                ->first();

            $isReplacement = $client !== null && $client->revoked_at === null;

            if ($client === null) {
                $client = BookingClient::query()->create(['type' => BookingClientType::ApiOnly, 'property_id' => $property->id, 'name' => $property->name]);
            } else {
                $client->tokens()->delete();
                $client->forceFill(['name' => $property->name, 'revoked_at' => null, 'last_used_at' => null])->save();
            }

            $token = $client->createToken($property->name, BookingClientType::ApiOnly->abilities())->plainTextToken;

            activity()
                ->performedOn($client)
                ->causedBy($actor)
                ->event($isReplacement ? 'booking-website.api-token-replaced' : 'booking-website.api-token-created')
                ->withProperties(['property_id' => $property->id])
                ->log($isReplacement ? 'Booking API token replaced' : 'Booking API token created');

            return $token;
        });
    }
}
