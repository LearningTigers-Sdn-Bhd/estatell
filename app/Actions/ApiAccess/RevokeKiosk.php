<?php

namespace App\Actions\ApiAccess;

use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke a kiosk. Its token is deleted and stops working at once, a pairing code on it dies, and the row stays so staff
 * still see it. Returns false, writing nothing, when it is already revoked.
 */
class RevokeKiosk
{
    public function __invoke(User $actor, Kiosk $kiosk): bool
    {
        if ($kiosk->revoked_at !== null) {
            return false;
        }

        DB::transaction(function () use ($actor, $kiosk): void {
            $kiosk->forceFill(['revoked_at' => now(), 'pairing_code_hash' => null, 'pairing_expires_at' => null])->save();
            $kiosk->tokens()->delete();

            activity()
                ->performedOn($kiosk)
                ->causedBy($actor)
                ->event('api-access.token-revoked')
                ->withProperties(['property_id' => $kiosk->property_id, 'name' => $kiosk->name])
                ->log('Kiosk revoked');
        });

        return true;
    }
}
