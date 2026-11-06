<?php

namespace App\Actions\ApiAccess;

use App\Enums\BookingClientType;
use App\Models\BookingClient;
use Illuminate\Support\Facades\DB;

/**
 * Create the one microsite client and its token. Returns the plain token, which is never stored or shown again, or null
 * when an active microsite client already exists.
 */
class CreateMicrositeClient
{
    public function __invoke(string $name): ?string
    {
        return DB::transaction(function () use ($name): ?string {
            $exists = BookingClient::query()->where('type', BookingClientType::Microsite)->whereNull('revoked_at')->lockForUpdate()->exists();

            if ($exists) {
                return null;
            }

            $client = BookingClient::query()->create(['type' => BookingClientType::Microsite, 'name' => $name]);

            return $client->createToken($name, BookingClientType::Microsite->abilities())->plainTextToken;
        });
    }
}
