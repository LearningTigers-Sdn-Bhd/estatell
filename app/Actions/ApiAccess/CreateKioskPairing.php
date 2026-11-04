<?php

namespace App\Actions\ApiAccess;

use App\Enums\ApiTokenKind;
use App\Models\ApiToken;
use App\Models\Property;
use App\Models\User;
use App\Services\ApiAccess\ApiSecrets;

/**
 * Staff add a kiosk. Returns the new row and the pairing code in plaintext; the code is shown once and valid for ten minutes.
 */
class CreateKioskPairing
{
    public const CODE_MINUTES = 10;

    /**
     * @return array{token: ApiToken, code: string}
     */
    public function __invoke(User $actor, Property $property, string $name): array
    {
        $code = ApiSecrets::newPairingCode();

        $token = $property->apiTokens()->create([
            'kind' => ApiTokenKind::Kiosk,
            'name' => $name,
            'scopes' => ApiTokenKind::Kiosk->defaultScopes(),
            'pairing_code_hash' => ApiSecrets::hash($code),
            'pairing_expires_at' => now()->addMinutes(self::CODE_MINUTES),
        ]);

        activity()
            ->performedOn($token)
            ->causedBy($actor)
            ->event('api-access.kiosk-added')
            ->withProperties(['property_id' => $property->id, 'name' => $name])
            ->log('Kiosk added');

        return ['token' => $token, 'code' => $code];
    }
}
