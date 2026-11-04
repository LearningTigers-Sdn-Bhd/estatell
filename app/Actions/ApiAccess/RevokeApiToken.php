<?php

namespace App\Actions\ApiAccess;

use App\Models\ApiToken;
use App\Models\User;

/**
 * Revoke a credential. It stops working at once and a pairing code on it dies. Returns false, writing nothing, when it is already revoked.
 */
class RevokeApiToken
{
    public function __invoke(User $actor, ApiToken $token): bool
    {
        if ($token->revoked_at !== null) {
            return false;
        }

        $token->forceFill(['revoked_at' => now(), 'pairing_code_hash' => null, 'pairing_expires_at' => null])->save();

        activity()
            ->performedOn($token)
            ->causedBy($actor)
            ->event('api-access.token-revoked')
            ->withProperties(['property_id' => $token->property_id, 'kind' => $token->kind->value, 'name' => $token->name])
            ->log('API credential revoked');

        return true;
    }
}
