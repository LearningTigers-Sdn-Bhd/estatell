<?php

namespace App\Services\ApiAccess;

use App\Models\ApiToken;
use Illuminate\Support\Str;

/**
 * Finds the credential behind a bearer token and says what it may do. Reads only.
 */
class ApiTokenGate
{
    /**
     * The usable token for a bearer string, or null when it is unknown, revoked, expired or its property is suspended.
     */
    public function find(?string $bearer): ?ApiToken
    {
        if ($bearer === null || ! Str::startsWith($bearer, ApiSecrets::TOKEN_PREFIX)) {
            return null;
        }

        $token = ApiToken::query()->with('property')->where('token_hash', ApiSecrets::hash($bearer))->first();

        if ($token === null || $token->revoked_at !== null || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return null;
        }

        return $token->property->suspended_at === null ? $token : null;
    }

    /**
     * Exact match on a space-separated scope list. A kiosk token carries `kiosk:*`, so it never matches an `/api/v1` scope.
     */
    public function allows(ApiToken $token, string $scope): bool
    {
        return in_array($scope, explode(' ', $token->scopes), true);
    }
}
