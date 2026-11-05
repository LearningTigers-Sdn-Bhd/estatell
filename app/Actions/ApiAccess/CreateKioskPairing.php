<?php

namespace App\Actions\ApiAccess;

use App\Models\Kiosk;
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
     * @return array{kiosk: Kiosk, code: string}
     */
    public function __invoke(User $actor, Property $property, string $name): array
    {
        $code = ApiSecrets::newPairingCode();

        $kiosk = $property->kiosks()->create([
            'name' => $name,
            'pairing_code_hash' => ApiSecrets::hash($code),
            'pairing_expires_at' => now()->addMinutes(self::CODE_MINUTES),
        ]);

        activity()
            ->performedOn($kiosk)
            ->causedBy($actor)
            ->event('api-access.kiosk-added')
            ->withProperties(['property_id' => $property->id, 'name' => $name])
            ->log('Kiosk added');

        return ['kiosk' => $kiosk, 'code' => $code];
    }
}
