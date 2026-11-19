<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Models\UserTrustedDevice;
use App\Services\Account\DeviceDescriber;
use App\Services\Account\TrustedDevices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class TrustDevice
{
    public function __construct(private TrustedDevices $trustedDevices, private DeviceDescriber $devices) {}

    /**
     * Trust this browser for the user for the next 90 days, or move an existing trust forward. The browser keeps its
     * device token when it has one, so the other users already trusted on it stay trusted. Pass the trust already
     * found for this browser to move it forward without looking it up again. Queues the cookie.
     */
    public function __invoke(User $user, Request $request, ?UserTrustedDevice $trusted = null): void
    {
        $token = $this->trustedDevices->token($request) ?? Str::random(40);
        $values = [
            'device_name' => $this->devices->label($request->userAgent()),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(TrustedDevices::TRUST_DAYS),
        ];

        if ($trusted !== null) {
            $trusted->update($values);
        } else {
            UserTrustedDevice::query()->updateOrCreate(['user_id' => $user->id, 'token_hash' => hash('sha256', $token)], $values);
        }

        Cookie::queue(Cookie::make(TrustedDevices::COOKIE, $token, TrustedDevices::TRUST_DAYS * 24 * 60, httpOnly: true, sameSite: 'lax'));
    }
}
