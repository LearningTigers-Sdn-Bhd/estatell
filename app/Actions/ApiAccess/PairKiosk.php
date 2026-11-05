<?php

namespace App\Actions\ApiAccess;

use App\Models\Kiosk;
use App\Services\ApiAccess\ApiSecrets;
use Illuminate\Support\Facades\DB;

/**
 * A kiosk trades its pairing code for a token. The code works once: the row is locked, checked and cleared in one
 * transaction. Returns null, writing nothing, for a wrong, used, expired or revoked code or a suspended property.
 */
class PairKiosk
{
    /**
     * @return array{kiosk: Kiosk, plain: string}|null
     */
    public function __invoke(string $code, ?string $deviceName): ?array
    {
        $hash = ApiSecrets::hash(ApiSecrets::normalizeCode($code));

        return DB::transaction(function () use ($hash, $deviceName): ?array {
            $kiosk = Kiosk::query()
                ->with('property')
                ->where('pairing_code_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($kiosk === null
                || $kiosk->revoked_at !== null
                || $kiosk->pairing_expires_at === null
                || $kiosk->pairing_expires_at->isPast()
                || $kiosk->property->suspended_at !== null) {
                return null;
            }

            $plain = $kiosk->createToken($kiosk->name, [Kiosk::TOKEN_ABILITY])->plainTextToken;

            $kiosk->forceFill([
                'pairing_code_hash' => null,
                'pairing_expires_at' => null,
                'device_name' => $deviceName,
                'paired_at' => now(),
            ])->save();

            activity()
                ->performedOn($kiosk)
                ->event('api-access.kiosk-paired')
                ->withProperties(['property_id' => $kiosk->property_id, 'device_name' => $deviceName])
                ->log('Kiosk paired');

            return ['kiosk' => $kiosk, 'plain' => $plain];
        });
    }
}
