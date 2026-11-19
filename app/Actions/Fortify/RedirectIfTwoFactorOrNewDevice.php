<?php

namespace App\Actions\Fortify;

use App\Actions\Account\SendNewDeviceCode;
use App\Actions\Account\TrustDevice;
use App\Models\User;
use App\Services\Account\TrustedDevices;
use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\LoginRateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's two-factor step of the login pipeline, extended with the new-device check. The password is checked once.
 * A user with two-factor gets Fortify's challenge. A user who needs the check and signs in on a browser that is not
 * trusted for them gets an emailed code and the verify-device page. See docs/01-platform/09-new-device-check.md.
 */
class RedirectIfTwoFactorOrNewDevice extends RedirectIfTwoFactorAuthenticatable
{
    /**
     * Session keys that hold the signed-in-to-be user while the code is pending.
     */
    public const string SESSION_USER_KEY = 'login.device.id';

    public const string SESSION_REMEMBER_KEY = 'login.device.remember';

    public function __construct(
        StatefulGuard $guard,
        LoginRateLimiter $limiter,
        private TrustedDevices $trustedDevices,
        private SendNewDeviceCode $sendNewDeviceCode,
        private TrustDevice $trustDevice,
    ) {
        parent::__construct($guard, $limiter);
    }

    /**
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     */
    public function handle($request, $next)
    {
        /** @var User $user */
        $user = $this->validateCredentials($request);

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        if (! $this->trustedDevices->requiresCheck($user)) {
            return $next($request);
        }

        $trusted = $this->trustedDevices->find($user, $request);

        if ($trusted !== null) {
            ($this->trustDevice)($user, $request, $trusted);

            return $next($request);
        }

        ($this->sendNewDeviceCode)($user, $request);

        $request->session()->put([
            self::SESSION_USER_KEY => $user->id,
            self::SESSION_REMEMBER_KEY => $request->boolean('remember'),
        ]);

        return $request->wantsJson()
            ? response()->json(['new_device' => true])
            : redirect()->route('login.otp.show');
    }
}
