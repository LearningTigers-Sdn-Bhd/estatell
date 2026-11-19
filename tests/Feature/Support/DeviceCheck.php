<?php

use App\Models\User;
use App\Models\UserTrustedDevice;
use App\Services\Account\TrustedDevices;
use Illuminate\Support\Str;

/**
 * Trust the test browser for the user, so a password sign-in skips the emailed new-device code. Returns the token.
 */
function trustTestBrowserFor(User $user): string
{
    $token = Str::random(40);
    UserTrustedDevice::factory()->for($user)->create(['token_hash' => hash('sha256', $token)]);
    test()->withCookie(TrustedDevices::COOKIE, $token);

    return $token;
}
