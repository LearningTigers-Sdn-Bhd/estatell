<?php

namespace App\Actions\ApiAccess;

use App\Enums\ApiTokenKind;
use App\Models\ApiToken;
use App\Services\ApiAccess\ApiSecrets;
use Illuminate\Support\Facades\DB;

/**
 * A kiosk trades its pairing code for a token. The code works once: the row is locked, checked and cleared in one
 * transaction. Returns null, writing nothing, for a wrong, used, expired or revoked code or a suspended property.
 */
class PairKiosk
{
    /**
     * @return array{token: ApiToken, plain: string}|null
     */
    public function __invoke(string $code, ?string $deviceName): ?array
    {
        $hash = ApiSecrets::hash(ApiSecrets::normalizeCode($code));

        return DB::transaction(function () use ($hash, $deviceName): ?array {
            $token = ApiToken::query()
                ->with('property')
                ->where('kind', ApiTokenKind::Kiosk)
                ->where('pairing_code_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($token === null
                || $token->revoked_at !== null
                || $token->pairing_expires_at === null
                || $token->pairing_expires_at->isPast()
                || $token->property->suspended_at !== null) {
                return null;
            }

            $plain = ApiSecrets::newToken();

            $token->forceFill([
                'token_hash' => ApiSecrets::hash($plain),
                'pairing_code_hash' => null,
                'pairing_expires_at' => null,
                'device_name' => $deviceName,
                'paired_at' => now(),
            ])->save();

            activity()
                ->performedOn($token)
                ->event('api-access.kiosk-paired')
                ->withProperties(['property_id' => $token->property_id, 'device_name' => $deviceName])
                ->log('Kiosk paired');

            return ['token' => $token, 'plain' => $plain];
        });
    }
}
