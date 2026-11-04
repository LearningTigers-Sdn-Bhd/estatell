<?php

namespace App\Actions\ApiAccess;

use App\Models\BookingClient;

/**
 * Stamp `last_used_at`, at most once a minute so a busy client does not write on every request.
 */
class RecordBookingClientUse
{
    public function __invoke(BookingClient $client): void
    {
        if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
