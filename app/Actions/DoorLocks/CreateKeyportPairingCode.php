<?php

namespace App\Actions\DoorLocks;

use App\Models\KeyportPairingCode;
use App\Models\Property;
use App\Models\User;

/**
 * Make a single-use pairing code for a new Keyport PC. Returns the code in the form `K7F2-9QXM`; only its hash is
 * saved, so this is the one time it can be shown.
 */
class CreateKeyportPairingCode
{
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __invoke(User $actor, Property $property): string
    {
        $characters = '';

        for ($i = 0; $i < 8; $i++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $code = substr($characters, 0, 4).'-'.substr($characters, 4);

        (new KeyportPairingCode)->forceFill([
            'property_id' => $property->id,
            'created_by' => $actor->id,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes((int) config('keyport.pairing_minutes')),
        ])->save();

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('door-locks.keyport-code-created')
            ->withProperties(['property_id' => $property->id])
            ->log('Keyport pairing code created');

        return $code;
    }
}
