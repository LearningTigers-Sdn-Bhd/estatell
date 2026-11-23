<?php

namespace App\Services\Account;

use App\Models\User;
use App\Models\UserTrustedDevice;
use Illuminate\Http\Request;

/**
 * Reads the new-device check: who needs it, and whether this browser already passed it for a user. See
 * docs/01-platform/09-new-device-check.md.
 */
class TrustedDevices
{
    /**
     * The cookie that holds this browser's device token. Laravel encrypts it like every cookie.
     */
    public const string COOKIE = 'estatell_device';

    /**
     * A trusted browser is trusted for this many days after its last sign-in.
     */
    public const int TRUST_DAYS = 90;

    /**
     * Platform admins, users with two-factor turned on (their code is the stronger check), staff created with a temp
     * password (their email may be made up) and anyone signing in on the demo environment sign in without the email code.
     */
    public function requiresCheck(User $user): bool
    {
        if (app()->environment('demo')) {
            return false;
        }

        return ! $user->is_admin && $user->email_device_check && ! $user->hasEnabledTwoFactorAuthentication();
    }

    /**
     * The unexpired trust of this browser for the user, or null.
     */
    public function find(User $user, Request $request): ?UserTrustedDevice
    {
        $token = $this->token($request);

        if ($token === null) {
            return null;
        }

        return UserTrustedDevice::query()
            ->whereBelongsTo($user)
            ->where('token_hash', hash('sha256', $token))
            ->active()
            ->first();
    }

    /**
     * The device token from the cookie, when it has the shape a token has.
     */
    public function token(Request $request): ?string
    {
        $token = $request->cookie(self::COOKIE);

        return is_string($token) && preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1 ? $token : null;
    }
}
