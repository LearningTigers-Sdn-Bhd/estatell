<?php

namespace App\Actions\Pos;

use App\Models\PosApiClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke a POS client. Its token is deleted and stops working at once; the row stays so staff still see it. Returns
 * false, writing nothing, when it is already revoked.
 */
class RevokePosApiClient
{
    public function __invoke(User $actor, PosApiClient $client): bool
    {
        if ($client->revoked_at !== null) {
            return false;
        }

        DB::transaction(function () use ($actor, $client): void {
            $client->forceFill(['revoked_at' => now()])->save();
            $client->tokens()->delete();

            activity()
                ->performedOn($client)
                ->causedBy($actor)
                ->event('api-access.token-revoked')
                ->withProperties(['property_id' => $client->property_id, 'name' => $client->name])
                ->log('POS API client revoked');
        });

        return true;
    }
}
