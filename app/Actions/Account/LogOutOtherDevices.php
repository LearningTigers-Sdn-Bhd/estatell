<?php

namespace App\Actions\Account;

use Illuminate\Support\Facades\Auth;

class LogOutOtherDevices
{
    /**
     * End every other session of the signed-in user. Laravel rehashes the password, so `auth.session`
     * signs out other sessions and "remember me" cookies on their next request. This session stays.
     */
    public function __invoke(#[\SensitiveParameter] string $password): void
    {
        Auth::logoutOtherDevices($password);
    }
}
